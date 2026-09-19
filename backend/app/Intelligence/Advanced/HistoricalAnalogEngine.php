<?php

namespace App\Intelligence\Advanced;

/**
 * No-lookahead historical analogs with sample guards.
 * Matches feature vectors only against past windows ending before as-of index.
 */
class HistoricalAnalogEngine
{
    public const MIN_SAMPLES = 5;

    public const WINDOW = 20;

    public const TOP_K = 5;

    /**
     * @param  list<array<string, mixed>>  $candles  chronological closed candles
     * @param  int|null  $asOfIndex  inclusive end index of query window (default: last)
     * @return array<string, mixed>
     */
    public function find(array $candles, ?int $asOfIndex = null): array
    {
        $n = count($candles);
        $asOf = $asOfIndex ?? ($n - 1);
        if ($asOf < self::WINDOW || $asOf >= $n) {
            return [
                'status' => 'INSUFFICIENT_DATA',
                'sample_size' => 0,
                'min_required' => self::MIN_SAMPLES,
                'guard' => 'SAMPLE_GUARD_ACTIVE',
                'analogs' => [],
                'lookahead_safe' => true,
            ];
        }

        $queryVec = $this->windowVector($candles, $asOf - self::WINDOW + 1, $asOf);
        $candidates = [];
        // Strict no-lookahead: candidate window must end before query window start
        $maxEnd = $asOf - self::WINDOW;
        for ($end = self::WINDOW - 1; $end <= $maxEnd; $end++) {
            $start = $end - self::WINDOW + 1;
            $vec = $this->windowVector($candles, $start, $end);
            $dist = $this->euclidean($queryVec, $vec);
            $fwd = null;
            // Forward return uses only candles after candidate end and still before asOf
            if ($end + 5 <= $asOf) {
                $c0 = (float) ($candles[$end]['close'] ?? 0);
                $c1 = (float) ($candles[$end + 5]['close'] ?? 0);
                $fwd = $c0 != 0.0 ? ($c1 - $c0) / $c0 : 0.0;
            }
            $candidates[] = [
                'end_index' => $end,
                'distance' => round($dist, 8),
                'forward_ret_5' => $fwd !== null ? round($fwd, 8) : null,
            ];
        }

        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        $top = array_slice($candidates, 0, self::TOP_K);
        $sampleSize = count($candidates);

        if ($sampleSize < self::MIN_SAMPLES) {
            return [
                'status' => 'INSUFFICIENT_SAMPLES',
                'sample_size' => $sampleSize,
                'min_required' => self::MIN_SAMPLES,
                'guard' => 'SAMPLE_GUARD_ACTIVE',
                'analogs' => $top,
                'as_of_index' => $asOf,
                'lookahead_safe' => true,
                'mean_forward_ret_5' => null,
            ];
        }

        $fwdVals = array_values(array_filter(
            array_map(fn ($a) => $a['forward_ret_5'], $top),
            fn ($v) => $v !== null
        ));
        $meanFwd = $fwdVals === [] ? null : array_sum($fwdVals) / count($fwdVals);

        return [
            'status' => 'OK',
            'sample_size' => $sampleSize,
            'min_required' => self::MIN_SAMPLES,
            'guard' => 'OK',
            'analogs' => $top,
            'as_of_index' => $asOf,
            'window' => self::WINDOW,
            'lookahead_safe' => true,
            'mean_forward_ret_5' => $meanFwd !== null ? round($meanFwd, 8) : null,
            'disclaimer' => 'Historical analogs are advisory research — not predictive guarantees.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return list<float>
     */
    private function windowVector(array $candles, int $start, int $end): array
    {
        $vec = [];
        for ($i = $start + 1; $i <= $end; $i++) {
            $prev = (float) ($candles[$i - 1]['close'] ?? 0);
            $cur = (float) ($candles[$i]['close'] ?? 0);
            $vec[] = $prev != 0.0 ? ($cur - $prev) / $prev : 0.0;
        }

        return $vec;
    }

    /** @param  list<float>  $a @param  list<float>  $b */
    private function euclidean(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }
}
