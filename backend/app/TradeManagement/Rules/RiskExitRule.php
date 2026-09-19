<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class RiskExitRule implements TradeManagementRule
{
    public function code(): string { return 'RISK_EXIT'; }
    public function priority(): int { return 20; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->risk_exit; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if (! ($context->risk['force_exit'] ?? false)) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::FullClose->value,
            'why' => 'Risk exit: risk state requires reducing DEMO exposure.',
            'proposed_close_volume' => (float) $position->current_volume,
            'payload' => ['protective' => true, 'close_reason' => 'RISK_EXIT'],
        ];
    }
}
