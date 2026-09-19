<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Enums\OrderDirection;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;
use App\TradeManagement\StopProtection;

final class BreakEvenRule implements TradeManagementRule
{
    public function code(): string { return 'BREAK_EVEN'; }
    public function priority(): int { return 60; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->break_even_enabled; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if ($position->break_even_applied) {
            return null;
        }
        $mark = $position->direction === OrderDirection::Buy ? $context->bid : $context->ask;
        $r = StopProtection::rMultiple($position->direction, (float) $position->entry_price, $position->initial_stop_loss !== null ? (float) $position->initial_stop_loss : null, $mark);
        $trigger = (float) $policy->break_even_trigger_value;
        if ($r === null || $r < $trigger) {
            return null;
        }
        $offset = (float) $policy->break_even_offset;
        $entry = (float) $position->entry_price;
        $proposed = $position->direction === OrderDirection::Buy ? $entry + $offset : $entry - $offset;
        $digits = (int) ($context->spec['digits'] ?? 5);
        $proposed = StopProtection::roundToDigits($proposed, $digits);
        $current = $position->current_stop_loss !== null ? (float) $position->current_stop_loss : null;
        if (! StopProtection::isStrictImprovement($position->direction, $current, $proposed)) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::MoveBreakEven->value,
            'why' => sprintf('Break-even triggered at R=%.3f (trigger %.3f).', $r, $trigger),
            'proposed_sl' => $proposed,
            'payload' => ['protective' => true, 'r_multiple' => $r, 'one_time' => true],
        ];
    }
}
