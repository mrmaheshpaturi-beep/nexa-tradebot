<?php

namespace App\Observability;

use App\Enums\AlertSeverity;
use App\Models\DataQualityScore;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Str;

/**
 * Data quality monitor + score.
 * Bad data → NO NEW TRADE.
 * Time sync / clock drift awareness.
 */
class DataQualityMonitor
{
    public const MIN_ACCEPTABLE_SCORE = 0.70;

    public const MAX_CLOCK_DRIFT_MS = 5000.0;

    public function __construct(
        private readonly AlertManager $alerts,
        private readonly StructuredLogger $logger,
    ) {}

    public function evaluate(
        string $source,
        float $score,
        ?string $symbol = null,
        array $issues = [],
        ?float $clockDriftMs = null,
    ): DataQualityScore {
        $blocks = $score < self::MIN_ACCEPTABLE_SCORE;
        $verdict = 'ACCEPTABLE';
        if ($clockDriftMs !== null && abs($clockDriftMs) > self::MAX_CLOCK_DRIFT_MS) {
            $blocks = true;
            $issues[] = 'CLOCK_DRIFT';
            $verdict = 'CLOCK_DRIFT';
        } elseif ($blocks) {
            $verdict = 'BAD_DATA';
        }

        $row = DataQualityScore::query()->create([
            'public_id' => (string) Str::uuid(),
            'symbol' => $symbol,
            'source' => $source,
            'score' => $score,
            'verdict' => $verdict,
            'blocks_new_trades' => $blocks && ObservabilitySafety::BAD_DATA_BLOCKS_NEW_TRADES,
            'issues' => $issues,
            'clock_drift_ms' => $clockDriftMs,
            'observed_at' => now(),
        ]);

        if ($row->blocks_new_trades) {
            $this->alerts->raise(
                'DATA_QUALITY',
                'Bad data blocks new trades',
                AlertSeverity::Critical,
                "source={$source} score={$score}",
                ['public_id' => $row->public_id, 'verdict' => $verdict],
            );
            $this->logger->warning('DataQualityMonitor', 'Blocking new trades due to data quality', [
                'public_id' => $row->public_id,
                'score' => $score,
            ]);
        }

        return $row;
    }

    public function currentlyBlocksNewTrades(): bool
    {
        $latest = DataQualityScore::query()->latest('id')->first();

        return (bool) ($latest?->blocks_new_trades);
    }

    public function latest(?string $symbol = null): ?DataQualityScore
    {
        $q = DataQualityScore::query()->orderByDesc('id');
        if ($symbol) {
            $q->where('symbol', $symbol);
        }

        return $q->first();
    }
}
