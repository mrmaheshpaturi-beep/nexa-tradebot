<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Enums\OrderDirection;
use App\Enums\TrailingType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;
use App\TradeManagement\StopProtection;

final class TrailingStopRule implements TradeManagementRule
{
    public function code(): string { return 'TRAILING_STOP'; }
    public function priority(): int { return 70; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->trailing_enabled; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        $mark = $position->direction === OrderDirection::Buy ? $context->bid : $context->ask;
        $entry = (float) $position->entry_price;
        $start = (float) ($policy->trailing_start ?? 0);
        $favorable = $position->direction === OrderDirection::Buy ? ($mark - $entry) : ($entry - $mark);
        if ($favorable < $start) {
            return null;
        }

        $distance = $this->distance($policy, $context);
        if ($distance <= 0) {
            return null;
        }
        $proposed = $position->direction === OrderDirection::Buy ? $mark - $distance : $mark + $distance;
        $digits = (int) ($context->spec['digits'] ?? 5);
        $proposed = StopProtection::roundToDigits($proposed, $digits);

        $step = (float) ($policy->trailing_step ?? 0);
        $last = $position->last_trail_stop !== null ? (float) $position->last_trail_stop : null;
        if ($last !== null && $step > 0) {
            $moved = abs($proposed - $last);
            if ($moved < $step) {
                return null;
            }
        }

        $current = $position->current_stop_loss !== null ? (float) $position->current_stop_loss : null;
        $proposed = StopProtection::clampNeverWorsen($position->direction, $current, $proposed);
        if (! StopProtection::isStrictImprovement($position->direction, $current, $proposed)) {
            return null;
        }

        $stopsLevel = (float) ($context->spec['trade_stops_level'] ?? 0);
        $point = (float) ($context->spec['point'] ?? 0.00001);
        if ($stopsLevel > 0) {
            $minDist = $stopsLevel * $point;
            $distNow = abs($mark - $proposed);
            if ($distNow < $minDist) {
                return null;
            }
        }

        return [
            'decision_type' => ManagementDecisionType::TrailStop->value,
            'why' => sprintf('Trail stop (%s) to %.5f.', $policy->trailing_type?->value ?? 'FIXED', $proposed),
            'proposed_sl' => $proposed,
            'payload' => [
                'protective' => true,
                'trailing_type' => $policy->trailing_type?->value,
                'distance' => $distance,
            ],
        ];
    }

    private function distance(TradeManagementPolicy $policy, ManagementContext $context): float
    {
        $type = $policy->trailing_type ?? TrailingType::FixedDistance;
        return match ($type) {
            TrailingType::FixedDistance => (float) ($policy->trailing_distance ?? 0),
            TrailingType::Percentage => abs($context->mid) * ((float) ($policy->trailing_distance ?? 0) / 100.0),
            TrailingType::AtrBased => (float) ($context->atr ?? 0) * (float) ($policy->trailing_atr_multiplier ?? 1),
            TrailingType::StructureBased => ($context->structureStop !== null
                ? abs($context->mid - (float) $context->structureStop)
                : (float) ($policy->trailing_distance ?? 0)),
        };
    }
}
