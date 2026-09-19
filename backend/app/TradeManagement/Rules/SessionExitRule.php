<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class SessionExitRule implements TradeManagementRule
{
    public function code(): string { return 'SESSION_EXIT'; }
    public function priority(): int { return 45; }
    public function enabled(TradeManagementPolicy $policy): bool
    {
        return (bool) $policy->session_exit || (bool) $policy->weekend_exit;
    }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if ($policy->session_exit && $context->sessionClosing) {
            return [
                'decision_type' => ManagementDecisionType::FullClose->value,
                'why' => 'Session exit policy triggered.',
                'proposed_close_volume' => (float) $position->current_volume,
                'payload' => ['protective' => true, 'close_reason' => 'SESSION_EXIT'],
            ];
        }
        if ($policy->weekend_exit && $context->weekendImminent) {
            return [
                'decision_type' => ManagementDecisionType::FullClose->value,
                'why' => 'Weekend policy exit triggered.',
                'proposed_close_volume' => (float) $position->current_volume,
                'payload' => ['protective' => true, 'close_reason' => 'WEEKEND_POLICY'],
            ];
        }
        return null;
    }
}
