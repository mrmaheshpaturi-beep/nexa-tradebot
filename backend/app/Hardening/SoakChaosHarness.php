<?php

namespace App\Hardening;

use App\Hardening\Support\HardeningSafety;
use App\Observability\FailureInjectionHarness;
use App\Observability\SoakTestFramework;

/**
 * Soak / chaos frameworks — TEST/DEMO only. Never against LIVE.
 * Does not claim multi-day soak executed in CI.
 */
class SoakChaosHarness
{
    public function __construct(
        private readonly SoakTestFramework $soak,
        private readonly FailureInjectionHarness $chaos,
        private readonly AppBrokerEnvironmentService $envs,
    ) {}

    /** @return array<string, mixed> */
    public function assertEnvironmentAllowed(): array
    {
        $current = $this->envs->current();
        $app = strtoupper((string) $current['app_environment']);
        $broker = strtoupper((string) $current['broker_trade_mode']);

        if (in_array($broker, HardeningSafety::FORBIDDEN_BROKER_MODES, true)) {
            throw new \RuntimeException('Soak/chaos against LIVE/UNKNOWN is forbidden.');
        }
        if (! in_array($app, HardeningSafety::SOAK_ALLOWED_ENVIRONMENTS, true)
            && ! in_array($app, HardeningSafety::APP_ENVIRONMENTS, true)) {
            throw new \RuntimeException('Soak/chaos environment not allowed.');
        }

        return ['app' => $app, 'broker' => $broker, 'allowed' => true];
    }

    /** @return array<string, mixed> */
    public function runCiSafeSoak(): array
    {
        $env = $this->assertEnvironmentAllowed();
        $result = $this->soak->runCiShort(fn (int $i): array => [
            'tick' => $i,
            'duplicate_orders' => false,
            'live' => false,
        ]);

        return [
            'environment' => $env,
            'result' => $result,
            'multi_day_soak_executed' => false,
            'claim' => 'CI_SAFE_FRAMEWORK_ONLY',
            'against_live' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function runChaosScenario(string $scenario): array
    {
        $env = $this->assertEnvironmentAllowed();
        $result = $this->chaos->run($scenario, fn (): array => [
            'injected' => true,
            'order_send' => false,
            'live' => false,
            'unknown_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
        ]);

        return [
            'environment' => $env,
            'scenario' => $scenario,
            'result' => $result,
            'against_live' => false,
            'order_send' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function posture(): array
    {
        return [
            'phase' => HardeningSafety::PHASE,
            'allowed_environments' => HardeningSafety::SOAK_ALLOWED_ENVIRONMENTS,
            'live_forbidden' => true,
            'multi_day_soak_in_ci' => 'NOT_CLAIMED',
            'status' => 'FRAMEWORK_READY',
            'manual_multi_day_soak' => 'PENDING',
        ];
    }
}
