<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Enums\OrderDirection;
use App\Enums\TargetHitStatus;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class PartialCloseRule implements TradeManagementRule
{
    public function code(): string { return 'PARTIAL_CLOSE'; }
    public function priority(): int { return 50; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->partial_close_enabled; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        $targets = $position->positionTargets()->orderBy('sequence')->get();
        if ($targets->isEmpty()) {
            return null;
        }
        $dir = $position->direction;
        $mark = $dir === OrderDirection::Buy ? $context->bid : $context->ask;
        foreach ($targets as $target) {
            if ($target->status !== TargetHitStatus::Pending) {
                continue;
            }
            $price = (float) $target->price;
            $hit = $dir === OrderDirection::Buy ? $mark >= $price : $mark <= $price;
            if (! $hit) {
                continue;
            }
            $pct = (float) $target->close_percent / 100.0;
            $vol = round((float) $position->initial_volume * $pct, 4);
            $vol = min($vol, (float) $position->current_volume);
            if ($vol <= 0) {
                return null;
            }
            return [
                'decision_type' => ManagementDecisionType::PartialClose->value,
                'why' => "Partial close at {$target->label} ({$target->close_percent}%).",
                'proposed_close_volume' => $vol,
                'payload' => [
                    'protective' => false,
                    'target_id' => $target->id,
                    'target_label' => $target->label,
                    'close_reason' => 'TAKE_PROFIT',
                ],
            ];
        }
        return null;
    }
}
