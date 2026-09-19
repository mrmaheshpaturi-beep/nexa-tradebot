<?php

namespace App\Intelligence;

/**
 * Confidence calibration with sample-size guards.
 * Never mixes evidence labels.
 */
class ConfidenceCalibration
{
    public const MIN_SAMPLES = 20;

    /**
     * @param  list<array{predicted_confidence: float, outcome_positive: ?bool}>  $samples
     * @return array<string, mixed>
     */
    public function calibrate(array $samples, string $evidenceLabel): array
    {
        $n = count($samples);
        if ($n < self::MIN_SAMPLES) {
            return [
                'status' => 'INSUFFICIENT_SAMPLES',
                'sample_size' => $n,
                'min_required' => self::MIN_SAMPLES,
                'evidence_label' => $evidenceLabel,
                'calibrated_confidence' => null,
                'brier_proxy' => null,
                'guard' => 'SAMPLE_GUARD_ACTIVE',
            ];
        }

        $sumPred = 0.0;
        $sumSqErr = 0.0;
        $withOutcome = 0;
        foreach ($samples as $s) {
            $p = (float) ($s['predicted_confidence'] ?? 0);
            $sumPred += $p;
            if (array_key_exists('outcome_positive', $s) && $s['outcome_positive'] !== null) {
                $y = $s['outcome_positive'] ? 1.0 : 0.0;
                $sumSqErr += ($p - $y) ** 2;
                $withOutcome++;
            }
        }

        $avgPred = $sumPred / $n;
        $brier = $withOutcome > 0 ? $sumSqErr / $withOutcome : null;

        // Simple reliability shrink toward 0.5 when poorly calibrated
        $calibrated = $avgPred;
        if ($brier !== null && $brier > 0.25) {
            $calibrated = 0.5 * $avgPred + 0.5 * 0.5;
        }

        return [
            'status' => 'OK',
            'sample_size' => $n,
            'min_required' => self::MIN_SAMPLES,
            'evidence_label' => $evidenceLabel,
            'mean_predicted' => round($avgPred, 4),
            'calibrated_confidence' => round($calibrated, 4),
            'brier_proxy' => $brier !== null ? round($brier, 6) : null,
            'outcomes_observed' => $withOutcome,
            'guard' => 'OK',
        ];
    }
}
