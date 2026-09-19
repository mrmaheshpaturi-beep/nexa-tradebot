<?php

namespace App\TradeManagement\Rules;

use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\ManagementContext;

final class StrategyInvalidationRule implements TradeManagementRule
{
    public function code(): string { return 'STRATEGY_INVALIDATION'; }
    public function priority(): int { return 30; }
    public function enabled(TradeManagementPolicy $policy): bool { return (bool) $policy->strategy_invalidation_exit; }

    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array
    {
        if (! $context->strategyInvalidated) {
            return null;
        }
        return [
            'decision_type' => ManagementDecisionType::FullClose->value,
            'why' => 'Strategy invalidation / signal reversal exit.',
            'proposed_close_volume' => (float) $position->current_volume,
            'payload' => ['protective' => true, 'close_reason' => 'STRATEGY_INVALIDATION'],
        ];
    }
}
