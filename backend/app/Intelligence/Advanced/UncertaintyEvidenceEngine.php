<?php

namespace App\Intelligence\Advanced;

use App\Intelligence\ConfidenceCalibration;

/**
 * Extends Phase 13 ConfidenceCalibration with uncertainty + evidence quality.
 */
class UncertaintyEvidenceEngine
{
    public function __construct(
        private readonly ConfidenceCalibration $calibration = new ConfidenceCalibration,
    ) {}

    /**
     * @param  list<array{predicted_confidence: float, outcome_positive: ?bool}>  $samples
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function evaluate(array $samples, string $evidenceLabel, array $context = []): array
    {
        $cal = $this->calibration->calibrate($samples, $evidenceLabel);

        $freshOk = (bool) ($context['features_fresh'] ?? true);
        $analogOk = ($context['analogs_status'] ?? 'OK') === 'OK';
        $evidenceOk = (bool) ($context['evidence_ok'] ?? true);
        $mq = (string) ($context['market_quality_status'] ?? 'UNKNOWN');

        $qualityScore = 0.0;
        $qualityScore += $freshOk ? 0.25 : 0.0;
        $qualityScore += $analogOk ? 0.25 : 0.0;
        $qualityScore += $evidenceOk ? 0.25 : 0.0;
        $qualityScore += in_array($mq, ['GOOD', 'OK', 'ACCEPTABLE'], true) ? 0.25 : 0.05;

        $uncertainty = 1.0 - $qualityScore;
        if (($cal['status'] ?? '') === 'INSUFFICIENT_SAMPLES') {
            $uncertainty = min(1.0, $uncertainty + 0.25);
        }
        if (! empty($context['ensemble_conflicts'])) {
            $uncertainty = min(1.0, $uncertainty + 0.1);
        }

        $band = $uncertainty >= 0.6 ? 'HIGH' : ($uncertainty >= 0.35 ? 'MEDIUM' : 'LOW');

        return [
            'calibration' => $cal,
            'evidence_quality' => [
                'score' => round($qualityScore, 4),
                'fresh' => $freshOk,
                'analogs_ok' => $analogOk,
                'evidence_separated' => $evidenceOk,
                'market_quality' => $mq,
                'label' => $qualityScore >= 0.75 ? 'HIGH' : ($qualityScore >= 0.45 ? 'MEDIUM' : 'LOW'),
            ],
            'uncertainty' => [
                'score' => round($uncertainty, 4),
                'band' => $band,
            ],
            'evidence_label' => $evidenceLabel,
            'guard' => $cal['guard'] ?? 'OK',
        ];
    }
}
