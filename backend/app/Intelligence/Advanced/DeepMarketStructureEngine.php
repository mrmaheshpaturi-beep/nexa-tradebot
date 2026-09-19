<?php

namespace App\Intelligence\Advanced;

use App\Intelligence\TechnicalMtfRegimeIntelligence;

/**
 * Deep market structure extending Phase 13 TechnicalMtfRegimeIntelligence.
 * Adds S/R zones, trend strength, momentum, volatility bands, MTF matrix.
 */
class DeepMarketStructureEngine
{
    public function __construct(
        private readonly TechnicalMtfRegimeIntelligence $base = new TechnicalMtfRegimeIntelligence,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  array<string, list<array<string, mixed>>>  $mtfCandles  e.g. M15/H1/H4
     * @return array<string, mixed>
     */
    public function analyze(array $candles, ?array $htfCandles = null, array $mtfCandles = []): array
    {
        $base = $this->base->analyze($candles, $htfCandles);
        $closes = array_map(fn ($c) => (float) ($c['close'] ?? 0), $candles);
        $highs = array_map(fn ($c) => (float) ($c['high'] ?? 0), $candles);
        $lows = array_map(fn ($c) => (float) ($c['low'] ?? 0), $candles);
        $n = count($closes);

        if ($n < 40) {
            return array_merge($base, [
                'structure' => ['status' => 'INSUFFICIENT_DATA', 'swings' => []],
                'support_resistance' => ['status' => 'INSUFFICIENT_DATA', 'zones' => []],
                'trend' => ['status' => 'INSUFFICIENT_DATA'],
                'momentum' => ['status' => 'INSUFFICIENT_DATA'],
                'volatility_ext' => ['status' => 'INSUFFICIENT_DATA'],
                'mtf_matrix' => ['status' => 'INSUFFICIENT_DATA', 'cells' => []],
            ]);
        }

        $swings = $this->swings($highs, $lows, $closes);
        $zones = $this->srZones($swings, $closes[$n - 1]);
        $trend = $this->trendStrength($closes, $base['technical'] ?? []);
        $momentum = $this->momentum($closes);
        $volExt = $this->volatilityExt($candles, $base['regime'] ?? []);
        $matrix = $this->mtfMatrix($base, $mtfCandles, $candles);

        return array_merge($base, [
            'structure' => [
                'status' => 'OK',
                'swings' => $swings,
                'hh_hl' => $trend['structure_label'] ?? 'UNKNOWN',
            ],
            'support_resistance' => [
                'status' => 'OK',
                'zones' => $zones,
                'nearest_support' => $zones['support'][0] ?? null,
                'nearest_resistance' => $zones['resistance'][0] ?? null,
            ],
            'trend' => $trend,
            'momentum' => $momentum,
            'volatility_ext' => $volExt,
            'mtf_matrix' => $matrix,
        ]);
    }

    /**
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<array<string, mixed>>
     */
    private function swings(array $highs, array $lows, array $closes): array
    {
        $out = [];
        $n = count($closes);
        for ($i = 2; $i < $n - 2; $i++) {
            if ($highs[$i] >= $highs[$i - 1] && $highs[$i] >= $highs[$i - 2]
                && $highs[$i] >= $highs[$i + 1] && $highs[$i] >= $highs[$i + 2]) {
                $out[] = ['type' => 'SWING_HIGH', 'index' => $i, 'price' => round($highs[$i], 8)];
            }
            if ($lows[$i] <= $lows[$i - 1] && $lows[$i] <= $lows[$i - 2]
                && $lows[$i] <= $lows[$i + 1] && $lows[$i] <= $lows[$i + 2]) {
                $out[] = ['type' => 'SWING_LOW', 'index' => $i, 'price' => round($lows[$i], 8)];
            }
        }

        return array_slice($out, -12);
    }

    /**
     * @param  list<array<string, mixed>>  $swings
     * @return array{support: list<array>, resistance: list<array>}
     */
    private function srZones(array $swings, float $last): array
    {
        $support = [];
        $resistance = [];
        foreach ($swings as $s) {
            $price = (float) $s['price'];
            $row = [
                'price' => $price,
                'distance' => round(abs($price - $last), 8),
                'source' => $s['type'],
            ];
            if ($price <= $last) {
                $support[] = $row;
            } else {
                $resistance[] = $row;
            }
        }
        usort($support, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        usort($resistance, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return [
            'support' => array_slice($support, 0, 3),
            'resistance' => array_slice($resistance, 0, 3),
        ];
    }

    /** @param  list<float>  $closes */
    private function trendStrength(array $closes, array $technical): array
    {
        $n = count($closes);
        $last = $closes[$n - 1];
        $sma20 = array_sum(array_slice($closes, -20)) / 20;
        $sma50 = array_sum(array_slice($closes, -min(50, $n))) / min(50, $n);
        $slope = ($sma20 - $sma50) / max(abs($sma50), 1e-9);
        $bias = $technical['bias'] ?? 'NEUTRAL';
        $strength = min(1.0, abs($slope) * 500);
        $label = abs($slope) > 0.0015 ? 'TRENDING' : 'BALANCED';
        if ($sma20 > $sma50 && $last > $sma20) {
            $structure = 'HH_HL_PROXY';
        } elseif ($sma20 < $sma50 && $last < $sma20) {
            $structure = 'LH_LL_PROXY';
        } else {
            $structure = 'MIXED';
        }

        return [
            'status' => 'OK',
            'bias' => $bias,
            'strength' => round($strength, 4),
            'slope' => round($slope, 8),
            'label' => $label,
            'structure_label' => $structure,
            'sma20' => round($sma20, 8),
            'sma50' => round($sma50, 8),
        ];
    }

    /** @param  list<float>  $closes */
    private function momentum(array $closes): array
    {
        $n = count($closes);
        $roc10 = $closes[$n - 11] != 0.0 ? ($closes[$n - 1] - $closes[$n - 11]) / $closes[$n - 11] : 0.0;
        $gains = 0.0;
        $losses = 0.0;
        for ($i = $n - 14; $i < $n; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d >= 0) {
                $gains += $d;
            } else {
                $losses -= $d;
            }
        }
        $rsi = $losses <= 1e-12 ? 100.0 : 100 - (100 / (1 + ($gains / $losses)));
        $label = $roc10 > 0.002 && $rsi > 55 ? 'BULLISH_MOMENTUM'
            : ($roc10 < -0.002 && $rsi < 45 ? 'BEARISH_MOMENTUM' : 'NEUTRAL_MOMENTUM');

        return [
            'status' => 'OK',
            'roc_10' => round($roc10, 8),
            'rsi_14' => round($rsi, 4),
            'label' => $label,
        ];
    }

    /** @param  list<array<string, mixed>>  $candles */
    private function volatilityExt(array $candles, array $regime): array
    {
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $h = (float) ($candles[$i]['high'] ?? 0);
            $l = (float) ($candles[$i]['low'] ?? 0);
            $pc = (float) ($candles[$i - 1]['close'] ?? 0);
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        $atr14 = count($trs) ? array_sum(array_slice($trs, -14)) / min(14, count($trs)) : 0.0;
        $atr50 = count($trs) ? array_sum(array_slice($trs, -50)) / min(50, count($trs)) : 0.0;
        $ratio = $atr50 > 0 ? $atr14 / $atr50 : 1.0;
        $band = $ratio > 1.4 ? 'EXPANDING' : ($ratio < 0.7 ? 'CONTRACTING' : 'STABLE');

        return [
            'status' => 'OK',
            'atr_14' => round($atr14, 8),
            'atr_50' => round($atr50, 8),
            'atr_ratio' => round($ratio, 4),
            'band' => $band,
            'regime_label' => $regime['label'] ?? 'UNKNOWN',
            'volatility_bucket' => $regime['volatility_bucket'] ?? 'UNKNOWN',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, list<array<string, mixed>>>  $mtfCandles
     * @param  list<array<string, mixed>>  $primary
     * @return array<string, mixed>
     */
    private function mtfMatrix(array $base, array $mtfCandles, array $primary): array
    {
        $cells = [
            [
                'timeframe' => 'PRIMARY',
                'bias' => $base['technical']['bias'] ?? 'NEUTRAL',
                'regime' => $base['regime']['label'] ?? 'UNKNOWN',
                'alignment' => $base['mtf']['alignment'] ?? 'UNKNOWN',
            ],
        ];
        if (($base['mtf']['status'] ?? '') === 'OK') {
            $cells[] = [
                'timeframe' => 'HTF',
                'bias' => $base['mtf']['htf_bias'] ?? 'NEUTRAL',
                'regime' => null,
                'alignment' => $base['mtf']['alignment'] ?? 'UNKNOWN',
            ];
        }
        foreach ($mtfCandles as $tf => $cndl) {
            if (! is_array($cndl) || count($cndl) < 20) {
                $cells[] = ['timeframe' => (string) $tf, 'bias' => 'UNKNOWN', 'status' => 'INSUFFICIENT_DATA'];
                continue;
            }
            $sub = $this->base->analyze($cndl, null);
            $cells[] = [
                'timeframe' => (string) $tf,
                'bias' => $sub['technical']['bias'] ?? 'NEUTRAL',
                'regime' => $sub['regime']['label'] ?? 'UNKNOWN',
                'alignment' => null,
                'status' => 'OK',
            ];
        }

        $biases = array_values(array_filter(array_map(fn ($c) => $c['bias'] ?? null, $cells)));
        $unique = array_unique($biases);
        $agreement = count($unique) === 1 && ($unique[0] ?? 'NEUTRAL') !== 'NEUTRAL'
            ? 'FULL'
            : (count($unique) <= 2 ? 'PARTIAL' : 'CONFLICTED');

        return [
            'status' => 'OK',
            'cells' => $cells,
            'agreement' => $agreement,
            'primary_candle_count' => count($primary),
        ];
    }
}
