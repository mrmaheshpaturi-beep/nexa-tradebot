<?php

namespace App\Observability;

use App\Observability\Support\ObservabilitySafety;

/**
 * Failure injection harness for tests (sections 148–165 style).
 * Never enables LIVE. Never sends real broker orders.
 */
class FailureInjectionHarness
{
    /** @var array<string, bool> */
    private array $flags = [];

    public function enable(string $scenario): void
    {
        $this->assertKnown($scenario);
        $this->flags[$scenario] = true;
    }

    public function disable(string $scenario): void
    {
        unset($this->flags[$scenario]);
    }

    public function active(string $scenario): bool
    {
        return (bool) ($this->flags[$scenario] ?? false);
    }

    /** @return list<string> */
    public function scenarios(): array
    {
        return [
            'stale_heartbeat',
            'bad_market_data',
            'clock_drift',
            'broker_disconnect',
            'ai_provider_down',
            'news_provider_down',
            'database_blip',
            'queue_overflow',
            'unknown_execution_state',
            'circuit_breaker_open',
            'disk_pressure',
            'memory_pressure',
            'alert_storm',
            'backup_verify_fail',
            'reconcile_mismatch',
            'watchdog_safe_mode',
            'rate_limit_trip',
            'secret_redaction_check',
        ];
    }

    public function run(string $scenario, callable $assertion): array
    {
        $this->enable($scenario);
        try {
            $result = $assertion();

            return [
                'scenario' => $scenario,
                'ok' => true,
                'result' => $result,
                'order_send' => false,
                'live' => false,
                'phase' => ObservabilitySafety::PHASE,
            ];
        } finally {
            $this->disable($scenario);
        }
    }

    private function assertKnown(string $scenario): void
    {
        if (! in_array($scenario, $this->scenarios(), true)) {
            throw new \InvalidArgumentException("Unknown failure injection scenario: {$scenario}");
        }
    }
}
