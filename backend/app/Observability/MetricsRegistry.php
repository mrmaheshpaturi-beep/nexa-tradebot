<?php

namespace App\Observability;

use App\Models\MetricSample;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Str;

/**
 * Metrics registry with strict evidence labeling.
 * DEMO / BACKTEST / WALK_FORWARD / DRY_RUN are never mixed.
 */
class MetricsRegistry
{
    public const MIN_SAMPLE_WARNING = 30;

    /** @var list<string> */
    public const CATEGORIES = [
        'market_data', 'scanner', 'strategy', 'candidate', 'intelligence',
        'qualification', 'risk', 'execution_quality', 'position', 'reconciliation',
        'automation', 'business_demo',
    ];

    public function record(
        string $name,
        float $value,
        string $category,
        string $evidenceLabel,
        array $labels = [],
        ?string $unit = null,
        int $sampleCount = 1,
    ): MetricSample {
        ObservabilitySafety::assertEvidenceLabel($evidenceLabel);
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Unknown metric category: {$category}");
        }

        $insufficient = $sampleCount < self::MIN_SAMPLE_WARNING;

        return MetricSample::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'category' => $category,
            'evidence_label' => strtoupper($evidenceLabel),
            'value' => $value,
            'unit' => $unit,
            'labels' => $labels,
            'insufficient_sample' => $insufficient,
            'sample_count' => $sampleCount,
            'observed_at' => now(),
        ]);
    }

    public function summarize(?string $evidenceLabel = null): array
    {
        $q = MetricSample::query()->orderByDesc('observed_at');
        if ($evidenceLabel !== null) {
            ObservabilitySafety::assertEvidenceLabel($evidenceLabel);
            $q->where('evidence_label', strtoupper($evidenceLabel));
        }

        $rows = $q->limit(500)->get();
        $byLabel = [];
        foreach (ObservabilitySafety::EVIDENCE_LABELS as $label) {
            $subset = $rows->where('evidence_label', $label);
            $byLabel[$label] = [
                'count' => $subset->count(),
                'insufficient_sample_warnings' => $subset->where('insufficient_sample', true)->count(),
                'latest' => $subset->take(10)->values()->map(fn (MetricSample $m) => [
                    'name' => $m->name,
                    'category' => $m->category,
                    'value' => (float) $m->value,
                    'sample_count' => $m->sample_count,
                    'insufficient_sample' => $m->insufficient_sample,
                    'observed_at' => $m->observed_at?->toIso8601String(),
                ])->all(),
            ];
        }

        return [
            'phase' => ObservabilitySafety::PHASE,
            'evidence_labels_separate' => true,
            'min_sample_warning_threshold' => self::MIN_SAMPLE_WARNING,
            'by_label' => $byLabel,
            'categories' => self::CATEGORIES,
        ];
    }
}
