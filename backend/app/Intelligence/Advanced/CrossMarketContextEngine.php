<?php

namespace App\Intelligence\Advanced;

/**
 * Cross-market rolling context from correlated symbols (deterministic).
 */
class CrossMarketContextEngine
{
    public const WINDOW = 40;

    /**
     * @param  array<string, list<array<string, mixed>>>  $symbolCandles
     * @return array<string, mixed>
     */
    public function build(string $primarySymbol, array $symbolCandles): array
    {
        $primary = $symbolCandles[$primarySymbol] ?? [];
        if (count($primary) < self::WINDOW) {
            return [
                'status' => 'INSUFFICIENT_DATA',
                'primary' => $primarySymbol,
                'correlations' => [],
                'rolling_window' => self::WINDOW,
            ];
        }

        $pRet = $this->returns(array_slice($primary, -self::WINDOW));
        $corrs = [];
        foreach ($symbolCandles as $sym => $candles) {
            if ($sym === $primarySymbol || count($candles) < self::WINDOW) {
                continue;
            }
            $r = $this->returns(array_slice($candles, -self::WINDOW));
            $len = min(count($pRet), count($r));
            if ($len < 10) {
                continue;
            }
            $corr = $this->pearson(array_slice($pRet, -$len), array_slice($r, -$len));
            $corrs[] = [
                'symbol' => (string) $sym,
                'correlation' => round($corr, 4),
                'relationship' => abs($corr) >= 0.6 ? ($corr > 0 ? 'POSITIVE' : 'INVERSE') : 'WEAK',
            ];
        }
        usort($corrs, fn ($a, $b) => abs($b['correlation']) <=> abs($a['correlation']));

        return [
            'status' => 'OK',
            'primary' => $primarySymbol,
            'rolling_window' => self::WINDOW,
            'correlations' => $corrs,
            'context_summary' => $corrs === []
                ? 'No cross-market peers supplied.'
                : sprintf('Top peer %s corr=%.2f', $corrs[0]['symbol'], $corrs[0]['correlation']),
            'lookahead_safe' => true,
        ];
    }

    /** @param  list<array<string, mixed>>  $candles @return list<float> */
    private function returns(array $candles): array
    {
        $closes = array_map(fn ($c) => (float) ($c['close'] ?? 0), $candles);
        $out = [];
        for ($i = 1; $i < count($closes); $i++) {
            $out[] = $closes[$i - 1] != 0.0 ? ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1] : 0.0;
        }

        return $out;
    }

    /** @param  list<float>  $a @param  list<float>  $b */
    private function pearson(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 2) {
            return 0.0;
        }
        $a = array_slice($a, 0, $n);
        $b = array_slice($b, 0, $n);
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $num = 0.0;
        $da = 0.0;
        $db = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $xa = $a[$i] - $ma;
            $xb = $b[$i] - $mb;
            $num += $xa * $xb;
            $da += $xa * $xa;
            $db += $xb * $xb;
        }
        $den = sqrt($da * $db);
        if ($den < 1e-12) {
            return 0.0;
        }

        return $num / $den;
    }
}
