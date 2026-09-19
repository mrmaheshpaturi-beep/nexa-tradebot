<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class EmergencyExitRule implements TradeManagementRule
{
    public function code(): string { return 'EMERGENCY_EXIT'; }
    public function priority(): int { return 10; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->emergency_exit; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if (! $context->emergency) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::FullClose->value,
            'why' => 'Emergency exit required by policy and active emergency condition.',
            'proposed_close_volume' => (float) $position->current_volume,
            'payload' => ['protective' => true, 'close_reason' => 'EMERGENCY_EXIT'],
        ];
    }
}
