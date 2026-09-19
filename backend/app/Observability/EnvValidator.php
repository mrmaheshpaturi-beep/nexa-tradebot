<?php

namespace App\Observability;

use App\Observability\Support\ObservabilitySafety;

/**
 * Environment validation + secret management checks.
 * Allowed: LOCAL | STAGING | DEMO_VPS. No LIVE_PRODUCTION.
 */
class EnvValidator
{
    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'APP_KEY',
        'APP_ENV',
        'DB_CONNECTION',
    ];

    /** @var list<string> */
    public const FORBIDDEN_LIVE_KEYS = [
        'ENABLE_LIVE_TRADING',
        'LIVE_AUTO',
        'ALLOW_LIVE_AUTO',
    ];

    public function validate(array $env): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::REQUIRED_KEYS as $key) {
            if (! filled($env[$key] ?? null)) {
                $errors[] = "Missing required env: {$key}";
            }
        }

        $opsEnv = strtoupper((string) ($env['NEXA_OPS_ENVIRONMENT'] ?? $env['NEXA_ENVIRONMENT'] ?? 'LOCAL'));
        try {
            ObservabilitySafety::assertEnvironmentAllowed($opsEnv);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        foreach (self::FORBIDDEN_LIVE_KEYS as $key) {
            if (array_key_exists($key, $env) && filter_var($env[$key], FILTER_VALIDATE_BOOLEAN)) {
                $errors[] = "Forbidden live flag set: {$key}";
            }
        }

        if (($env['ALLOW_LIVE_EXECUTION'] ?? 'false') === 'true' || ($env['ALLOW_LIVE_EXECUTION'] ?? false) === true) {
            $errors[] = 'ALLOW_LIVE_EXECUTION must remain false';
        }

        if (! empty($env['MT5_PASSWORD']) && app()->environment('testing') === false) {
            $warnings[] = 'MT5 credentials should use secret manager / encrypted storage — never commit .env';
        }

        return [
            'ok' => $errors === [],
            'ops_environment' => $opsEnv,
            'allowed_environments' => ObservabilitySafety::ALLOWED_ENVIRONMENTS,
            'forbidden_environments' => ObservabilitySafety::FORBIDDEN_ENVIRONMENTS,
            'errors' => $errors,
            'warnings' => $warnings,
            'dotenv_ignored' => true,
            'live_production' => 'DOES_NOT_EXIST',
        ];
    }

    public function validateCurrent(): array
    {
        return $this->validate([
            'APP_KEY' => config('app.key'),
            'APP_ENV' => config('app.env'),
            'DB_CONNECTION' => config('database.default'),
            'NEXA_OPS_ENVIRONMENT' => env('NEXA_OPS_ENVIRONMENT', 'LOCAL'),
            'ALLOW_LIVE_EXECUTION' => env('ALLOW_LIVE_EXECUTION', 'false'),
            'ENABLE_LIVE_TRADING' => env('ENABLE_LIVE_TRADING', 'false'),
            'LIVE_AUTO' => env('LIVE_AUTO', 'false'),
        ]);
    }
}
