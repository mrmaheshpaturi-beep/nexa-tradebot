<?php

namespace App\Intelligence\Advanced;

/**
 * Suitability analysis — advisory fit of opportunity vs risk/session/context.
 * Never mutates risk profiles or approves trades.
 */
class SuitabilityAnalysisEngine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function analyze(array $input): array
    {
        $reasons = [];
        $score = 70.0;

        $mq = (string) ($input['market_quality_status'] ?? 'UNKNOWN');
        if (in_array($mq, ['POOR', 'UNAVAILABLE', 'BAD'], true)) {
            $score -= 25;
            $reasons[] = 'Market quality unsuitable';
        } elseif ($mq === 'GOOD' || $mq === 'OK') {
            $score += 5;
            $reasons[] = 'Market quality acceptable';
        }

        $eventRisk = (string) ($input['event_risk'] ?? 'LOW');
        if ($eventRisk === 'HIGH') {
            $score -= 20;
            $reasons[] = 'High-impact event risk';
        } elseif ($eventRisk === 'MEDIUM') {
            $score -= 8;
            $reasons[] = 'Medium event risk';
        }

        $uncertainty = (float) ($input['uncertainty_score'] ?? 0.5);
        if ($uncertainty >= 0.6) {
            $score -= 15;
            $reasons[] = 'High uncertainty';
        } elseif ($uncertainty <= 0.3) {
            $score += 5;
            $reasons[] = 'Low uncertainty';
        }

        $agreement = (string) ($input['mtf_agreement'] ?? 'PARTIAL');
        if ($agreement === 'CONFLICTED') {
            $score -= 12;
            $reasons[] = 'MTF conflicted';
        } elseif ($agreement === 'FULL') {
            $score += 8;
            $reasons[] = 'MTF aligned';
        }

        $governance = (string) ($input['governance_filter_status'] ?? 'OK');
        if ($governance === 'NO_APPROVED_INPUT') {
            $score -= 10;
            $reasons[] = 'No governance-approved strategies in ensemble';
        }

        $mode = strtoupper((string) ($input['mode'] ?? 'ADVISORY'));
        if (! in_array($mode, ['ADVISORY', 'SHADOW'], true)) {
            $score = 0;
            $reasons[] = 'Invalid mode — only ADVISORY/SHADOW allowed';
        }

        $score = max(0.0, min(100.0, $score));
        $label = $score >= 70 ? 'SUITABLE' : ($score >= 45 ? 'MARGINAL' : 'UNSUITABLE');

        return [
            'status' => 'OK',
            'score' => round($score, 2),
            'label' => $label,
            'reasons' => $reasons,
            'mode' => $mode,
            'execution_authority' => false,
            'risk_mutation' => false,
            'approval_mutation' => false,
            'disclaimer' => 'Suitability is advisory research — Phase 9 RiskEngine and Phase 14 qualification remain mandatory before any execution path.',
        ];
    }
}
