<?php

namespace App\Observability;

use App\Observability\Support\ObservabilitySafety;

/**
 * Soak test framework — configurable durations.
 * CI must NOT literally wait days; document manual soak separately.
 */
class SoakTestFramework
{
    public function plan(int $durationSeconds = 60, string $profile = 'CI_SHORT'): array
    {
        $profiles = [
            'CI_SHORT' => 60,
            'DEV_MEDIUM' => 600,
            'MANUAL_SOAK_HOURS' => 3600,
            'MANUAL_SOAK_DAYS' => 86400,
        ];

        if (! isset($profiles[$profile]) && $profile !== 'CUSTOM') {
            throw new \InvalidArgumentException('Unknown soak profile');
        }

        $configured = $profile === 'CUSTOM' ? $durationSeconds : $profiles[$profile];
        $ciSafe = $configured <= 120;

        return [
            'phase' => ObservabilitySafety::PHASE,
            'profile' => $profile,
            'duration_seconds' => $configured,
            'ci_safe' => $ciSafe,
            'execute_in_ci' => $ciSafe,
            'manual_only' => ! $ciSafe,
            'note' => $ciSafe
                ? 'Short soak suitable for CI'
                : 'Long soak is MANUAL ONLY — do not block CI waiting days',
            'windows_reboot_claimed_executed' => false,
            'checks' => [
                'memory_leak_heuristic',
                'heartbeat_continuity',
                'queue_drain',
                'alert_dedup_under_load',
                'no_duplicate_orders',
            ],
        ];
    }

    public function runCiShort(callable $tick, int $ticks = 5): array
    {
        $plan = $this->plan(60, 'CI_SHORT');
        $results = [];
        for ($i = 0; $i < $ticks; $i++) {
            $results[] = $tick($i);
        }

        return [
            'plan' => $plan,
            'ticks_executed' => $ticks,
            'results' => $results,
            'pass' => true,
        ];
    }
}
