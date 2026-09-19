<?php

namespace App\Intelligence;

/**
 * Technical + MTF + regime intelligence derived from closed-candle proxies.
 * Deterministic — no paid APIs.
 */
class TechnicalMtfRegimeIntelligence
{
    /**
     * @param  list<array<string, mixed>>  $candles  closed OHLC
     * @param  list<array<string, mixed>>|null  $htfCandles
     * @return array{technical: array, mtf: array, regime: array}
     */
    public function analyze(array $candles, ?array $htfCandles = null): array
    {
        $closes = array_map(fn ($c) => (float) ($c['close'] ?? 0), $candles);
        $n = count($closes);
        if ($n < 20) {
            return [
                'technical' => [
                    'status' => 'INSUFFICIENT_DATA',
                    'bias' => 'NEUTRAL',
                    'rsi' => null,
                    'ema_fast' => null,
                    'ema_slow' => null,
                    'atr' => null,
                ],
                'mtf' => [
                    'status' => 'INSUFFICIENT_DATA',
                    'alignment' => 'UNKNOWN',
                    'htf_bias' => 'NEUTRAL',
                ],
                'regime' => [
                    'status' => 'INSUFFICIENT_DATA',
                    'label' => 'UNKNOWN',
                    'volatility_bucket' => 'UNKNOWN',
                ],
            ];
        }

        $emaFast = $this->ema($closes, 12);
        $emaSlow = $this->ema($closes, 26);
        $rsi = $this->rsi($closes, 14);
        $atr = $this->atr($candles, 14);
        $last = $closes[$n - 1];
        $bias = 'NEUTRAL';
        if ($emaFast > $emaSlow && $rsi >= 45) {
            $bias = 'BULLISH';
        } elseif ($emaFast < $emaSlow && $rsi <= 55) {
            $bias = 'BEARISH';
        }

        $htfBias = 'NEUTRAL';
        $mtfStatus = 'NO_HTF';
        if (is_array($htfCandles) && count($htfCandles) >= 20) {
            $htfCloses = array_map(fn ($c) => (float) ($c['close'] ?? 0), $htfCandles);
            $hf = $this->ema($htfCloses, 12);
            $hs = $this->ema($htfCloses, 26);
            $htfBias = $hf >= $hs ? 'BULLISH' : 'BEARISH';
            $mtfStatus = 'OK';
        }

        $alignment = 'MIXED';
        if ($mtfStatus === 'OK') {
            if ($bias === $htfBias) {
                $alignment = 'ALIGNED';
            } elseif ($bias === 'NEUTRAL') {
                $alignment = 'HTF_DOMINANT';
            } else {
                $alignment = 'DIVERGENT';
            }
        }

        $returns = [];
        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i - 1] != 0.0) {
                $returns[] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
            }
        }
        $vol = $this->stdev($returns);
        $regime = 'RANGE';
        if ($vol > 0.0012 && abs($emaFast - $emaSlow) / max($last, 1e-9) > 0.0008) {
            $regime = 'TREND';
        } elseif ($vol > 0.002) {
            $regime = 'HIGH_VOL';
        } elseif ($vol < 0.0004) {
            $regime = 'LOW_VOL';
        }

        $volBucket = $vol > 0.002 ? 'HIGH' : ($vol < 0.0005 ? 'LOW' : 'MEDIUM');

        return [
            'technical' => [
                'status' => 'OK',
                'bias' => $bias,
                'rsi' => round($rsi, 4),
                'ema_fast' => round($emaFast, 8),
                'ema_slow' => round($emaSlow, 8),
                'atr' => round($atr, 8),
                'last_close' => $last,
            ],
            'mtf' => [
                'status' => $mtfStatus,
                'alignment' => $alignment,
                'htf_bias' => $htfBias,
                'ltf_bias' => $bias,
            ],
            'regime' => [
                'status' => 'OK',
                'label' => $regime,
                'volatility_bucket' => $volBucket,
                'realized_vol' => round($vol, 8),
            ],
        ];
    }

    /** @param list<float> $xs */
    private function ema(array $xs, int $period): float
    {
        $k = 2 / ($period + 1);
        $ema = $xs[0];
        foreach ($xs as $x) {
            $ema = $x * $k + $ema * (1 - $k);
        }

        return $ema;
    }

    /** @param list<float> $xs */
    private function rsi(array $xs, int $period): float
    {
        $gains = 0.0;
        $losses = 0.0;
        $start = max(1, count($xs) - $period);
        for ($i = $start; $i < count($xs); $i++) {
            $d = $xs[$i] - $xs[$i - 1];
            if ($d >= 0) {
                $gains += $d;
            } else {
                $losses -= $d;
            }
        }
        if ($losses <= 1e-12) {
            return 100.0;
        }
        $rs = $gains / $losses;

        return 100 - (100 / (1 + $rs));
    }

    /** @param list<array<string, mixed>> $candles */
    private function atr(array $candles, int $period): float
    {
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $h = (float) ($candles[$i]['high'] ?? 0);
            $l = (float) ($candles[$i]['low'] ?? 0);
            $pc = (float) ($candles[$i - 1]['close'] ?? 0);
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        $slice = array_slice($trs, -$period);
        if ($slice === []) {
            return 0.0;
        }

        return array_sum($slice) / count($slice);
    }

    /** @param list<float> $xs */
    private function stdev(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($xs) / $n;
        $acc = 0.0;
        foreach ($xs as $x) {
            $acc += ($x - $mean) ** 2;
        }

        return sqrt($acc / ($n - 1));
    }
}
