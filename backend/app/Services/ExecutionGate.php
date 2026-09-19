<?php

namespace App\Services;

use App\Enums\AccountTradeMode;
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

        if ($environment === TradingEnvironment::Live || $account->environment === TradingEnvironment::Live) {
            throw ValidationException::withMessages([
                'environment' => 'LIVE execution is hard-disabled.',
            ]);
        }

        if ($environment === TradingEnvironment::Paper || $account->environment === TradingEnvironment::Paper) {
            throw ValidationException::withMessages([
                'environment' => 'PAPER execution is not implemented.',
            ]);
        }

        $errors = [];

        if ($environment === TradingEnvironment::Simulation) {
            if ($account->environment !== TradingEnvironment::Simulation) {
                $errors['environment'] = 'Only SIMULATION execution is implemented for simulation accounts.';
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
        } elseif ($environment === TradingEnvironment::Demo) {
            if ($account->environment !== TradingEnvironment::Demo) {
                $errors['environment'] = 'DEMO execution requires a DEMO broker account.';
            }
            if (! $account->is_enabled) {
                $errors['account'] = 'The DEMO account is disabled.';
            }
            if ($this->settings->value('emergency_stop') !== false) {
                $errors['emergency_stop'] = 'Emergency stop is active.';
            }
            if ($this->settings->value('allow_demo_execution') !== true) {
                $errors['allow_demo_execution'] = 'DEMO execution is disabled.';
            }
            // Phase 14: auto_demo_execution may be true for DEMO_AUTO orchestrator path.
            // It never authorizes LIVE and never bypasses DEMO verification below.
            if ($this->settings->value('allow_live_execution') === true) {
                $errors['allow_live_execution'] = 'LIVE flag must remain false.';
            }
            $mode = AccountTradeMode::fromBridge($account->verified_trade_mode);
            if ($mode === AccountTradeMode::Live) {
                $errors['trade_mode'] = 'LIVE trade mode hard-fails at ExecutionGate.';
            }
            if ($mode === AccountTradeMode::Unknown) {
                $errors['trade_mode'] = 'UNKNOWN account trade mode hard-fails at ExecutionGate.';
            }
            if ($mode !== AccountTradeMode::Demo) {
                $errors['demo_verification'] = 'DEMO account trade mode must be verified before execution.';
            }
            if (! $account->broker_login || ! $account->broker_server) {
                $errors['account_mapping'] = 'DEMO login/server mapping is required.';
            }
        } else {
            $errors['environment'] = 'Unsupported execution environment.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
