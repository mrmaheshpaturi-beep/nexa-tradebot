<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;

final class ExposureCorrelationRule implements RiskRule
{
    public function code(): string
    {
        return 'EXPOSURE_CORRELATION';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 60;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $ctx = $context->accountContext;
        $profile = $context->profile;

        if ((int) $ctx['open_positions'] >= (int) $profile->max_open_positions) {
            $context->fail(RiskReasonCode::MaxOpenPositions, 'Maximum open positions reached.', [
                'open_positions' => (int) $ctx['open_positions'],
                'max_open_positions' => (int) $profile->max_open_positions,
            ], $this->code());

            return;
        }

        if ((int) $ctx['trades_today'] >= (int) $profile->max_trades_per_day) {
            $context->fail(RiskReasonCode::MaxExposure, 'Maximum trades per day reached.', [
                'trades_today' => (int) $ctx['trades_today'],
                'max_trades_per_day' => (int) $profile->max_trades_per_day,
            ], $this->code());

            return;
        }

        $openRisk = (float) $ctx['open_risk_percent'] + (float) $context->sizing['proposed_risk_percent'];
        if ($openRisk + 0.00000001 > (float) $profile->max_open_risk) {
            $context->fail(RiskReasonCode::MaxExposure, 'Open risk exposure exceeds profile limit.', [
                'open_risk_percent' => round($openRisk, 4),
                'max_open_risk' => (float) $profile->max_open_risk,
            ], $this->code());

            return;
        }

        $correlated = (float) $ctx['correlated_exposure_percent'] + (float) $context->sizing['proposed_risk_percent'];
        $maxCorr = (float) ($profile->max_correlated_exposure ?? $profile->max_open_risk);
        if ($correlated + 0.00000001 > $maxCorr) {
            $context->fail(RiskReasonCode::CorrelationLimit, 'Correlated exposure exceeds profile limit.', [
                'correlated_exposure_percent' => round($correlated, 4),
                'max_correlated_exposure' => $maxCorr,
                'symbol' => $context->instrument->symbol,
            ], $this->code());

            return;
        }

        $context->pass($this->code(), [
            'open_positions' => (int) $ctx['open_positions'],
            'open_risk_percent' => round($openRisk, 4),
            'correlated_exposure_percent' => round($correlated, 4),
        ]);
    }
}
