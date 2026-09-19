<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;

final class MarginSpreadSessionRule implements RiskRule
{
    public function code(): string
    {
        return 'MARGIN_SPREAD_SESSION';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 70;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $ctx = $context->accountContext;
        $profile = $context->profile;
        $quote = $context->quote;

        $proposedMargin = (float) $context->sizing['proposed_margin'];
        $freeMargin = (float) $ctx['free_margin'] - (float) $ctx['reserved_margin'];
        if ($proposedMargin > $freeMargin + 0.00000001) {
            $context->fail(RiskReasonCode::MarginLimit, 'Insufficient free margin after reservations.', [
                'proposed_margin' => $proposedMargin,
                'free_margin' => (float) $ctx['free_margin'],
                'reserved_margin' => (float) $ctx['reserved_margin'],
            ], $this->code());

            return;
        }

        $equity = max((float) $ctx['equity'], 0.00000001);
        $usedMargin = (float) $ctx['margin'] + (float) $ctx['reserved_margin'] + $proposedMargin;
        $projectedLevel = $usedMargin > 0 ? ($equity / $usedMargin) * 100 : null;
        if ($projectedLevel !== null && $projectedLevel + 0.00000001 < (float) $profile->min_margin_level) {
            $context->fail(RiskReasonCode::MarginLimit, 'Projected margin level below profile minimum.', [
                'projected_margin_level' => round($projectedLevel, 2),
                'min_margin_level' => (float) $profile->min_margin_level,
            ], $this->code());

            return;
        }

        $spreadPoints = $quote['spread_points'];
        // Profile max_spread is configured in pips; convert to points via symbol digits (pip = 10 points on 5-digit FX).
        $digits = (int) ($quote['digits'] ?? $context->symbolSpecs['digits'] ?? 5);
        $pointsPerPip = $digits >= 3 ? 10.0 : 1.0;
        $maxSpreadPoints = (float) $profile->max_spread * $pointsPerPip;
        if ($spreadPoints !== null && (float) $spreadPoints + 0.00000001 > $maxSpreadPoints) {
            $context->fail(RiskReasonCode::SpreadLimit, 'Current spread exceeds profile maximum.', [
                'spread_points' => (float) $spreadPoints,
                'max_spread_pips' => (float) $profile->max_spread,
                'max_spread_points' => $maxSpreadPoints,
            ], $this->code());

            return;
        }

        $allowlist = $profile->session_allowlist;
        if (is_array($allowlist) && $allowlist !== []) {
            $active = $context->symbolSpecs['active_sessions'] ?? [];
            $overlap = array_values(array_intersect($allowlist, is_array($active) ? $active : []));
            if ($overlap === []) {
                $context->fail(RiskReasonCode::SessionRestricted, 'Symbol is outside allowed trading sessions.', [
                    'allowlist' => $allowlist,
                    'active_sessions' => $active,
                ], $this->code());

                return;
            }
        }

        $marketStatus = $context->symbolSpecs['market_status'] ?? null;
        $enforceHours = (bool) (($profile->rule_config['enforce_market_hours'] ?? false));
        if ($enforceHours && $marketStatus === 'CLOSED') {
            $context->fail(RiskReasonCode::SessionRestricted, 'Market is closed for this symbol.', [
                'market_status' => $marketStatus,
            ], $this->code());

            return;
        }

        $context->pass($this->code(), [
            'proposed_margin' => $proposedMargin,
            'projected_margin_level' => $projectedLevel,
            'spread_points' => $spreadPoints,
        ]);
    }
}
