<?php

namespace App\Hardening;

use App\Hardening\Support\HardeningSafety;
use App\Observability\ResourceMonitor;

/**
 * Performance / capacity planning signals for operators.
 */
class PerformanceCapacityService
{
    public function __construct(private readonly ResourceMonitor $resources) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $res = $this->resources->snapshot();

        return [
            'phase' => HardeningSafety::PHASE,
            'resources' => $res,
            'capacity' => [
                'queue_pending_warn' => 100,
                'queue_pending_critical' => 500,
                'worker_stale_seconds' => 120,
                'db_connection_budget' => 'MONITOR',
                'mt5_terminal_slots' => 'FLEET_SCOPED',
            ],
            'recommendations' => [
                'Scale automation workers before pending > warn threshold',
                'Never claim LIVE capacity — LIVE hard-blocked',
                'Prefer horizontal DEMO terminals over single overloaded node',
            ],
            'live_capacity_planning' => 'NOT_APPLICABLE',
        ];
    }
}
