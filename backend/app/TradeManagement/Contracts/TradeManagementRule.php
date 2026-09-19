<?php

namespace App\TradeManagement\Contracts;

use App\Models\ManagedPosition;
use App\Models\TradeManagementPolicy;
use App\TradeManagement\ManagementContext;

interface TradeManagementRule
{
    public function code(): string;

    /** Lower number = higher priority (Phase 11 §12). */
    public function priority(): int;

    public function enabled(TradeManagementPolicy $policy): bool;

    /**
     * @return array{decision_type:string,why:string,proposed_sl?:?float,proposed_tp?:?float,proposed_close_volume?:?float,payload?:array}|null
     */
    public function evaluate(ManagedPosition $position, TradeManagementPolicy $policy, ManagementContext $context): ?array;
}
