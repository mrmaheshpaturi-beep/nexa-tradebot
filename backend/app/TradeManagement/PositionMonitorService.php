<?php

namespace App\TradeManagement;

use App\Enums\ManagementStatus;
use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Models\ManagedPosition;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded-frequency position monitor. Event-driven evaluate preferred; poll is capped.
 */
class PositionMonitorService
{
    public function __construct(private readonly TradeManagementEngineService $engine) {}

    /**
     * @return list<array{decision:\App\Models\TradeManagementDecision,action:?\App\Models\PositionManagementAction}>
     */
    public function tick(?User $actor = null, int $maxPositions = 50, int $minIntervalSeconds = 5): array
    {
        $lockKey = 'trade_management:monitor_tick';
        if (! Cache::add($lockKey, 1, $minIntervalSeconds)) {
            return [];
        }

        $query = ManagedPosition::query()
            ->where('ownership', PositionOwnership::NexaManaged)
            ->where('environment', TradingEnvironment::Demo)
            ->where('auto_management_paused', false)
            ->whereIn('management_status', [
                ManagementStatus::Managing->value,
                ManagementStatus::Protecting->value,
                ManagementStatus::Eligible->value,
            ])
            ->orderBy('last_managed_at')
            ->limit($maxPositions);

        $out = [];
        foreach ($query->get() as $position) {
            $user = $actor ?? $position->user;
            $out[] = $this->engine->evaluate($position, $user, [], true);
        }

        return $out;
    }
}
