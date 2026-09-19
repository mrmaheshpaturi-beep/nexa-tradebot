<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class TakeProfitRule implements TradeManagementRule
{
    public function code(): string { return 'TAKE_PROFIT'; }
    public function priority(): int { return 80; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->take_profit_management; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        $desired = $context->extras['desired_tp'] ?? null;
        if ($desired === null) {
            return null;
        }
        $desired = (float) $desired;
        $current = $position->current_take_profit !== null ? (float) $position->current_take_profit : null;
        if ($current !== null && abs($current - $desired) < 1e-9) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::UpdateTp->value,
            'why' => 'Take-profit management update.',
            'proposed_tp' => $desired,
            'payload' => ['protective' => false],
        ];
    }
}
