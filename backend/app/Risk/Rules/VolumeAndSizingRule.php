<?php

namespace App\Risk\Rules;

use App\Enums\OrderDirection;
use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;
use App\Services\FinancialCalculator;

final class VolumeAndSizingRule implements RiskRule
{
    public function __construct(private readonly FinancialCalculator $calculator) {}

    public function code(): string
    {
        return 'VOLUME_SIZING';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 30;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $volume = (float) $context->sizing['proposed_volume'];
        $profile = $context->profile;
        $instrument = $context->instrument;

        if (! $this->calculator->isVolumeValid($instrument, $volume)) {
            $context->fail(RiskReasonCode::InvalidVolume, 'Volume violates instrument min/max/step.', [
                'volume' => $volume,
                'min' => $instrument->minimum_volume,
                'max' => $instrument->maximum_volume,
                'step' => $instrument->step_volume,
            ], $this->code());

            return;
        }
        if ($volume > (float) $profile->max_lot_size + 0.00000001) {
            $context->fail(RiskReasonCode::MaxLot, 'Volume exceeds profile max lot size.', [
                'volume' => $volume,
                'max_lot_size' => (float) $profile->max_lot_size,
            ], $this->code());

            return;
        }
        if ($volume <= 0) {
            $context->fail(RiskReasonCode::InsufficientEquity, 'Proposed volume is zero after sizing constraints.', [
                'sizing' => $context->sizing['breakdown'],
            ], $this->code());

            return;
        }

        $riskPercent = (float) $context->sizing['proposed_risk_percent'];
        if ($riskPercent > (float) $profile->max_risk_per_trade + 0.00000001) {
            $context->fail(RiskReasonCode::RiskLimit, 'Calculated risk exceeds the profile limit.', [
                'risk_percent' => $riskPercent,
                'max_risk_per_trade' => (float) $profile->max_risk_per_trade,
            ], $this->code());

            return;
        }

        $context->pass($this->code(), [
            'proposed_volume' => $volume,
            'risk_percent' => $riskPercent,
            'side' => $context->intent->side instanceof OrderDirection
                ? $context->intent->side->value
                : (string) $context->intent->side,
        ]);
    }
}
