<?php

namespace App\Services;

/**
 * Phase 5 data quality scoring and strategy-facing gate (no execution).
 */
class MarketDataQualityService
{
    /**
     * @param  list<array<string, mixed>>  $quotes
     * @param  list<array<string, mixed>>  $candles
     * @return array{status:string,score:int,issues:list<string>,usable_for_analysis:bool}
     */
    public function evaluate(array $quotes, array $candles = [], bool $providerConnected = true): array
    {
        $issues = [];
        if (! $providerConnected) {
            $issues[] = 'PROVIDER_DISCONNECTED';
        }
        $stale = 0;
        $invalid = 0;
        foreach ($quotes as $quote) {
            $qualityIssues = $quote['quality']['issues'] ?? [];
            if (in_array('STALE', $qualityIssues, true) || ($quote['freshness']['is_stale'] ?? false)) {
                $stale++;
                $issues[] = 'STALE_QUOTE:'.($quote['symbol'] ?? '?');
            }
            if (! ($quote['quality']['usable'] ?? false)) {
                $invalid++;
                $issues[] = 'INVALID_QUOTE:'.($quote['symbol'] ?? '?');
            }
        }
        foreach ($candles as $candle) {
            if (in_array('OHLC_INCONSISTENT', $candle['quality']['issues'] ?? [], true)) {
                $issues[] = 'INVALID_OHLC:'.($candle['symbol'] ?? '?');
                $invalid++;
            }
        }

        $score = 100;
        if (! $providerConnected) {
            $score -= 60;
        }
        $score -= min(40, $stale * 10);
        $score -= min(40, $invalid * 15);
        $score = max(0, $score);
        $status = match (true) {
            $score >= 80 => 'GOOD',
            $score >= 50 => 'DEGRADED',
            $score >= 1 => 'BAD',
            default => 'UNAVAILABLE',
        };

        return [
            'status' => $status,
            'score' => $score,
            'issues' => array_values(array_unique($issues)),
            'usable_for_analysis' => $this->allowsAnalysis($status, $providerConnected),
        ];
    }

    /**
     * Future strategies must call this before analysis. Phase 5 never executes.
     */
    public function allowsAnalysis(string $qualityStatus, bool $providerConnected = true): bool
    {
        if (! $providerConnected) {
            return false;
        }

        return in_array($qualityStatus, ['GOOD', 'DEGRADED'], true);
    }

    /**
     * @param  array<string, mixed>  $quality
     * @return array{allowed:bool,reason:string|null,quality:array<string,mixed>}
     */
    public function gate(array $quality): array
    {
        $allowed = (bool) ($quality['usable_for_analysis'] ?? false);

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : 'STALE_OR_INVALID_MARKET_DATA',
            'quality' => $quality,
            'phase' => 5,
            'execution' => false,
        ];
    }
}
