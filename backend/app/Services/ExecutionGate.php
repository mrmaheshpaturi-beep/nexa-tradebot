<?php

namespace App\Services;

use App\Enums\TradingEnvironment;
use App\Models\BrokerAccount;
use Illuminate\Validation\ValidationException;

class ExecutionGate
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertCanExecute(TradingEnvironment|string $environment, BrokerAccount $account): void
    {
        $environment = $environment instanceof TradingEnvironment
            ? $environment
            : TradingEnvironment::from($environment);

        $errors = [];
        if (! $environment->isExecutable() || $account->environment !== TradingEnvironment::Simulation) {
            $errors['environment'] = 'Only SIMULATION execution is implemented.';
        }
        if (! $account->is_enabled) {
            $errors['account'] = 'The simulation account is disabled.';
        }
        if ($this->settings->value('emergency_stop') !== false) {
            $errors['emergency_stop'] = 'Emergency stop is active.';
        }
        if ($this->settings->value('simulation_execution_enabled') !== true) {
            $errors['simulation_execution_enabled'] = 'Simulation execution is disabled.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
