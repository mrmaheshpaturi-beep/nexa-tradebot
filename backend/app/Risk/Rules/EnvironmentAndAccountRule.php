<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Enums\TradingEnvironment;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;
use App\Services\SettingsService;

final class EnvironmentAndAccountRule implements RiskRule
{
    public function __construct(private readonly SettingsService $settings) {}

    public function code(): string
    {
        return 'ENVIRONMENT_ACCOUNT';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 10;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $intent = $context->intent;
        $account = $intent->brokerAccount;
        $profile = $context->profile;

        if ($intent->environment !== TradingEnvironment::Simulation || $account->environment !== TradingEnvironment::Simulation) {
            $context->fail(RiskReasonCode::InvalidEnvironment, 'Only SIMULATION intents can be approved.', [
                'intent_environment' => $intent->environment->value,
                'account_environment' => $account->environment->value,
            ], $this->code());

            return;
        }
        if ($this->settings->value('emergency_stop') !== false) {
            $context->fail(RiskReasonCode::EmergencyStop, 'Emergency stop is active.', [], $this->code());

            return;
        }
        if ($this->settings->value('simulation_execution_enabled') !== true) {
            $context->fail(RiskReasonCode::SimulationDisabled, 'Simulation execution is disabled.', [], $this->code());

            return;
        }
        if (! $account->is_enabled || $profile->status !== 'ACTIVE') {
            $context->fail(RiskReasonCode::InvalidAccount, 'The account or its risk profile is not active.', [
                'account_enabled' => $account->is_enabled,
                'profile_status' => $profile->status,
            ], $this->code());

            return;
        }
        $context->pass($this->code(), ['environment' => TradingEnvironment::Simulation->value]);
    }
}
