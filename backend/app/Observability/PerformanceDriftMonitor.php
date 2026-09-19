<?php

namespace App\Observability;

use App\Enums\AlertSeverity;
use App\Models\PerformanceDriftCheck;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Str;

/**
 * Performance drift monitor.
 * Does NOT auto-disable strategies on noise.
 * Safety-based blocking is allowed.
 */
class PerformanceDriftMonitor
{
    public function __construct(
        private readonly AlertManager $alerts,
        private readonly StructuredLogger $logger,
    ) {}

    public function check(
        string $strategyKey,
        string $evidenceLabel,
        array $metrics,
        bool $safetyIssue = false,
    ): PerformanceDriftCheck {
        ObservabilitySafety::assertEvidenceLabel($evidenceLabel);

        $sampleCount = (int) ($metrics['sample_count'] ?? 0);
        $driftPct = (float) ($metrics['drift_pct'] ?? 0);
        $noisy = $sampleCount < MetricsRegistry::MIN_SAMPLE_WARNING || abs($driftPct) < 5.0;

        $verdict = 'STABLE';
        $safetyBlock = false;
        if ($safetyIssue) {
            $verdict = 'SAFETY_BLOCK';
            $safetyBlock = true;
        } elseif ($noisy) {
            $verdict = 'NOISE_INSUFFICIENT_SAMPLE';
        } elseif (abs($driftPct) >= 25.0) {
            $verdict = 'DRIFT_DETECTED';
        }

        $row = PerformanceDriftCheck::query()->create([
            'public_id' => (string) Str::uuid(),
            'strategy_key' => $strategyKey,
            'evidence_label' => strtoupper($evidenceLabel),
            'verdict' => $verdict,
            'auto_disable' => false, // never auto-disable on noise
            'safety_block' => $safetyBlock,
            'metrics' => $metrics,
            'notes' => $noisy
                ? 'Noise/insufficient sample — no auto strategy disable'
                : ($safetyBlock ? 'Safety-based block only' : null),
            'checked_at' => now(),
        ]);

        if ($safetyBlock) {
            $this->alerts->raise(
                'PERFORMANCE_DRIFT',
                "Safety block for strategy {$strategyKey}",
                AlertSeverity::Critical,
                'Safety-based blocking — not profit noise',
                ['public_id' => $row->public_id],
            );
        }

        $this->logger->info('PerformanceDriftMonitor', 'Drift check', [
            'verdict' => $verdict,
            'auto_disable' => false,
            'safety_block' => $safetyBlock,
        ]);

        return $row;
    }
}
