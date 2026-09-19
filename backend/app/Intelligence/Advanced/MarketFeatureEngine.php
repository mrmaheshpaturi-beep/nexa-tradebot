<?php

namespace App\Intelligence\Advanced;

/**
 * Versioned / freshness-gated market feature vector (deterministic).
 * Schema: market-features/v1 — closed candles only; no lookahead.
 */
class MarketFeatureEngine
{
    public const SCHEMA_VERSION = 'market-features/v1';

    public const MAX_AGE_SECONDS = 900;

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return array<string, mixed>
     */
    public function extract(array $candles, ?int $asOfEpoch = null, ?int $observedEpoch = null): array
    {
        $asOf = $asOfEpoch ?? time();
        $observed = $observedEpoch ?? $asOf;
        $age = max(0, $observed - $asOf);
        $fresh = $age <= self::MAX_AGE_SECONDS;

        $n = count($candles);
        if ($n < 30) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'INSUFFICIENT_DATA',
                'fresh' => false,
                'age_seconds' => $age,
                'max_age_seconds' => self::MAX_AGE_SECONDS,
                'feature_hash' => null,
                'features' => [],
            ];
        }

        $closes = array_map(fn ($c) => (float) ($c['close'] ?? 0), $candles);
        $highs = array_map(fn ($c) => (float) ($c['high'] ?? 0), $candles);
        $lows = array_map(fn ($c) => (float) ($c['low'] ?? 0), $candles);
        $last = $closes[$n - 1];
        $ret1 = $closes[$n - 2] != 0.0 ? ($last - $closes[$n - 2]) / $closes[$n - 2] : 0.0;
        $ret5 = $closes[$n - 6] != 0.0 ? ($last - $closes[$n - 6]) / $closes[$n - 6] : 0.0;
        $ret20 = $closes[$n - 21] != 0.0 ? ($last - $closes[$n - 21]) / $closes[$n - 21] : 0.0;

        $range20 = 0.0;
        for ($i = $n - 20; $i < $n; $i++) {
            $range20 = max($range20, $highs[$i] - $lows[$i]);
        }

        $features = [
            'close' => round($last, 8),
            'ret_1' => round($ret1, 8),
            'ret_5' => round($ret5, 8),
            'ret_20' => round($ret20, 8),
            'range_20' => round($range20, 8),
            'hl_mid' => round(($highs[$n - 1] + $lows[$n - 1]) / 2, 8),
            'candle_count' => $n,
            'as_of_epoch' => $asOf,
            'observed_epoch' => $observed,
        ];

        $hash = hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'features' => $features,
        ], JSON_THROW_ON_ERROR));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $fresh ? 'OK' : 'STALE',
            'fresh' => $fresh,
            'age_seconds' => $age,
            'max_age_seconds' => self::MAX_AGE_SECONDS,
            'feature_hash' => $hash,
            'features' => $features,
            'lookahead_safe' => true,
        ];
    }
}
