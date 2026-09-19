<?php

namespace App\Hardening;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningConfigValidation;
use App\Observability\EnvValidator;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Explicit app-vs-broker environment separation + config validation + safe defaults.
 * App env (LOCAL|STAGING|DEMO_VPS) is independent of broker trade mode (SIMULATION|DEMO).
 */
class AppBrokerEnvironmentService
{
    public function __construct(
        private readonly EnvValidator $envValidator,
        private readonly SettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function safeDefaults(): array
    {
        return [
            'app_environment' => 'LOCAL',
            'broker_trade_mode' => 'SIMULATION',
            'allow_live_execution' => false,
            'auto_trading_enabled' => false,
            'auto_demo_execution' => false,
            'emergency_stop' => true,
            'trading_enabled' => false,
            'simulation_execution_enabled' => false,
            'allow_demo_execution' => false,
            'live_auto_exists' => false,
            'unknown_execution_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
            'blind_retry_on_unknown' => 'NONE',
            'phase_10_sole_execution' => true,
            'phase_9_risk_mandatory' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $appEnv = strtoupper((string) env('NEXA_OPS_ENVIRONMENT', env('NEXA_ENVIRONMENT', 'LOCAL')));
        $brokerMode = strtoupper((string) (
            $this->settings->value('broker_trade_mode')
            ?? env('NEXA_BROKER_TRADE_MODE', 'SIMULATION')
        ));

        return [
            'app_environment' => $appEnv,
            'broker_trade_mode' => $brokerMode,
            'separation' => 'EXPLICIT',
            'note' => 'App environment and broker trade mode are independent axes — never conflate DEMO_VPS with LIVE.',
            'safe_defaults' => $this->safeDefaults(),
            'matrix' => HardeningSafety::matrix(),
        ];
    }

    /** @return array<string, mixed> */
    public function validate(?string $appEnv = null, ?string $brokerMode = null): array
    {
        $errors = [];
        $warnings = [];
        $current = $this->current();
        $app = strtoupper($appEnv ?? $current['app_environment']);
        $broker = strtoupper($brokerMode ?? $current['broker_trade_mode']);

        try {
            HardeningSafety::assertAppEnvironment($app);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            HardeningSafety::assertBrokerTradeMode($broker);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        $envCheck = $this->envValidator->validateCurrent();
        if (! $envCheck['ok']) {
            $errors = array_merge($errors, $envCheck['errors'] ?? []);
        }
        $warnings = array_merge($warnings, $envCheck['warnings'] ?? []);

        if ($this->settings->value('allow_live_execution') === true) {
            $errors[] = 'allow_live_execution must remain false';
        }

        $ok = $errors === [];
        $row = HardeningConfigValidation::query()->create([
            'public_id' => (string) Str::uuid(),
            'app_environment' => $app,
            'broker_trade_mode' => $broker,
            'ok' => $ok,
            'errors' => $errors,
            'warnings' => $warnings,
            'safe_defaults' => $this->safeDefaults(),
            'validated_at' => now(),
        ]);

        return [
            'ok' => $ok,
            'public_id' => $row->public_id,
            'app_environment' => $app,
            'broker_trade_mode' => $broker,
            'errors' => $errors,
            'warnings' => $warnings,
            'safe_defaults' => $this->safeDefaults(),
            'live_auto_exists' => false,
            'live_unknown' => 'HARD_BLOCKED',
        ];
    }
}
