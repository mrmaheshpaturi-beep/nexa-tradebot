<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class TimeExitRule implements TradeManagementRule
{
    public function code(): string { return 'TIME_EXIT'; }
    public function priority(): int { return 40; }
    public function enabled(TradeManagementPolicy $policy): bool
    {
        return (bool) $policy->time_exit_enabled && $policy->maximum_trade_duration_minutes;
    }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if (! $position->opened_at) {
            return null;
        }
        $max = (int) $policy->maximum_trade_duration_minutes;
        if ($position->opened_at->diffInMinutes(now()) < $max) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::FullClose->value,
            'why' => "Time exit: trade exceeded {$max} minutes.",
            'proposed_close_volume' => (float) $position->current_volume,
            'payload' => ['protective' => true, 'close_reason' => 'TIME_EXIT'],
        ];
    }
}
