<?php

namespace App\Intelligence;

/**
 * Market quality / volatility / spread / anomaly engines — fail-closed advisory.
 */
class MarketQualityEngines
{
    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  array<string, mixed>  $opts
     * @return array{market_quality: array, volatility: array, spread: array, anomaly: array}
     */
    public function evaluate(array $candles, array $opts = []): array
    {
        $n = count($candles);
        if ($n < 10) {
            return [
                'market_quality' => ['status' => 'UNAVAILABLE', 'reason' => 'INSUFFICIENT_CANDLES'],
                'volatility' => ['status' => 'UNAVAILABLE', 'atr' => null, 'bucket' => 'UNKNOWN'],
                'spread' => ['status' => 'UNAVAILABLE', 'points' => null, 'cap_points' => (float) ($opts['spread_cap'] ?? 5)],
                'anomaly' => ['detected' => false, 'status' => 'UNAVAILABLE', 'detail' => 'Insufficient data'],
            ];
        }

        $spreadPts = (float) ($opts['spread_points'] ?? 1.2);
        $cap = (float) ($opts['spread_cap'] ?? 5);
        $gaps = 0;
        $spike = false;
        for ($i = 1; $i < $n; $i++) {
            $prevClose = (float) ($candles[$i - 1]['close'] ?? 0);
            $open = (float) ($candles[$i]['open'] ?? 0);
            $high = (float) ($candles[$i]['high'] ?? 0);
            $low = (float) ($candles[$i]['low'] ?? 0);
            if ($prevClose > 0 && abs($open - $prevClose) / $prevClose > 0.003) {
                $gaps++;
            }
            if ($prevClose > 0 && ($high - $low) / $prevClose > 0.008) {
                $spike = true;
            }
        }

        $quality = 'GOOD';
        if ($gaps >= 3 || $spike) {
            $quality = 'DEGRADED';
        }
        if ($gaps >= 6 || $spreadPts > $cap * 2) {
            $quality = 'BAD';
        }

        $closes = array_map(fn ($c) => (float) ($c['close'] ?? 0), $candles);
        $rets = [];
        for ($i = 1; $i < count($closes); $i++) {
            if ($closes[$i - 1] != 0.0) {
                $rets[] = abs(($closes[$i] - $closes[$i - 1]) / $closes[$i - 1]);
            }
        }
        $avgAbs = $rets === [] ? 0.0 : array_sum($rets) / count($rets);
        $volBucket = $avgAbs > 0.0015 ? 'HIGH' : ($avgAbs < 0.0004 ? 'LOW' : 'MEDIUM');

        return [
            'market_quality' => [
                'status' => $quality,
                'gap_count' => $gaps,
                'spike_flag' => $spike,
            ],
            'volatility' => [
                'status' => 'OK',
                'avg_abs_return' => round($avgAbs, 8),
                'bucket' => $volBucket,
            ],
            'spread' => [
                'status' => $spreadPts > $cap ? 'WIDE' : 'OK',
                'points' => $spreadPts,
                'cap_points' => $cap,
            ],
            'anomaly' => [
                'detected' => $spike || $gaps >= 4,
                'status' => 'OK',
                'detail' => $spike ? 'Intrabar range spike' : ($gaps >= 4 ? 'Multiple open gaps' : 'None'),
            ],
        ];
    }
}
