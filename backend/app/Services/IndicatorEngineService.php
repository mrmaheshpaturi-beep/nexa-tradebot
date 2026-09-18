<?php

namespace App\Services;

use App\Contracts\IndicatorProvider;
use App\Indicators\AtrIndicator;
use App\Indicators\BollingerBandsIndicator;
use App\Indicators\EmaIndicator;
use App\Indicators\MacdIndicator;
use App\Indicators\RsiIndicator;
use App\Indicators\SmaIndicator;
use App\Models\ServiceHeartbeat;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Phase 6 Indicator Engine — central authority for indicator calculation.
 * Consumes closed candles from MarketDataEngine only. Never calls MT5/bridge.
 */
class IndicatorEngineService
{
    /** @var array<string, IndicatorProvider> */
    private array $providers;

    public function __construct(
        private readonly MarketDataEngineService $market,
        private readonly MarketDataQualityService $quality,
        private readonly IndicatorCache $cache,
    ) {
        $instances = [
            new SmaIndicator,
            new EmaIndicator,
            new RsiIndicator,
            new MacdIndicator,
            new AtrIndicator,
            new BollingerBandsIndicator,
        ];
        $this->providers = [];
        foreach ($instances as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $rows = [];
        foreach ($this->providers as $provider) {
            $rows[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'overlay' => $provider->overlay(),
                'params' => $provider->defaultParams(),
                'outputs' => $provider->outputs(),
                'consumes' => 'MarketDataEngineService::getClosedCandles',
                'read_only' => true,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function compute(
        string $indicator,
        string $symbol,
        string $timeframe = 'M5',
        int $count = 120,
        array $params = [],
        string $prefer = 'auto',
        bool $useCache = true,
    ): array {
        $indicator = strtoupper($indicator);
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $prefer = strtolower($prefer);
        $provider = $this->providers[$indicator] ?? null;
        if ($provider === null) {
            throw new InvalidArgumentException("Unknown indicator: {$indicator}");
        }
        $mergedParams = array_merge($provider->defaultParams(), $params);

        $cacheKey = $this->cache->key($symbol, $timeframe, $indicator, $mergedParams, $prefer, $count);
        if ($useCache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $cached['cache_hit'] = true;

                return $cached;
            }
        }

        // Closed candles only — never treat forming candles as finalized.
        $candles = $this->market->getClosedCandles($symbol, $timeframe, $count, $prefer);
        $snapshot = $this->market->snapshot([$symbol], $symbol, $timeframe, min(20, $count), $prefer, persist: false);
        $quality = $snapshot['data_quality'] ?? $this->quality->evaluate($snapshot['quotes'] ?? [], $candles);
        $gate = $this->quality->gate($quality);
        $source = (string) ($snapshot['source'] ?? 'UNKNOWN');
        $environment = (string) ($snapshot['environment'] ?? 'SIMULATION');

        if (! ($gate['allowed'] ?? false) || in_array($quality['status'] ?? '', ['BAD', 'UNAVAILABLE'], true)) {
            $payload = $this->envelope(
                $indicator,
                $mergedParams,
                $symbol,
                $timeframe,
                $source,
                $environment,
                status: 'REFUSED',
                reason: $gate['reason'] ?? 'MARKET_DATA_QUALITY_GATE',
                quality: $quality,
                gate: $gate,
                series: [],
                values: [],
                candleCount: count($candles),
            );
            $this->touchHeartbeat(false);
            $this->cache->put($cacheKey, $payload);

            return $payload;
        }

        $computed = $provider->compute($candles, $mergedParams);
        $status = ($quality['status'] ?? 'GOOD') === 'DEGRADED' ? 'DEGRADED' : 'READY';
        $payload = $this->envelope(
            $indicator,
            $mergedParams,
            $symbol,
            $timeframe,
            $source,
            $environment,
            status: $status,
            reason: null,
            quality: $quality,
            gate: $gate,
            series: $computed['series'] ?? [],
            values: $computed['values'] ?? [],
            candleCount: count($candles),
            overlay: $provider->overlay(),
            outputs: $provider->outputs(),
        );
        $this->touchHeartbeat(true);
        $this->cache->put($cacheKey, $payload);

        return $payload;
    }

    /**
     * Convenience alias for compute with series emphasis.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function series(
        string $indicator,
        string $symbol,
        string $timeframe = 'M5',
        int $count = 120,
        array $params = [],
        string $prefer = 'auto',
    ): array {
        return $this->compute($indicator, $symbol, $timeframe, $count, $params, $prefer);
    }

    /**
     * @param  list<string>  $indicators
     * @param  array<string, array<string, mixed>>  $paramsByIndicator
     * @return list<array<string, mixed>>
     */
    public function computeMany(
        array $indicators,
        string $symbol,
        string $timeframe = 'M5',
        int $count = 120,
        array $paramsByIndicator = [],
        string $prefer = 'auto',
    ): array {
        $rows = [];
        foreach ($indicators as $name) {
            $rows[] = $this->compute(
                $name,
                $symbol,
                $timeframe,
                $count,
                $paramsByIndicator[strtoupper($name)] ?? [],
                $prefer,
            );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $heartbeat = ServiceHeartbeat::query()->where('service', 'INDICATOR_ENGINE')->latest('observed_at')->first();

        return [
            'engine' => 'INDICATOR_ENGINE',
            'phase' => 6,
            'status' => 'READY',
            'read_only' => true,
            'providers' => array_keys($this->providers),
            'consumes' => 'MarketDataEngineService::getClosedCandles',
            'cache' => 'Illuminate Cache (no Redis required)',
            'heartbeat' => $heartbeat,
            'execution' => [
                'order_send' => false,
                'demo' => false,
                'live' => false,
            ],
            'extension_hooks' => [
                'phase_7_strategies' => 'READY',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $quality
     * @param  array<string, mixed>  $gate
     * @param  list<array<string, mixed>>  $series
     * @param  array<string, mixed>  $values
     * @param  list<string>  $outputs
     * @return array<string, mixed>
     */
    private function envelope(
        string $indicator,
        array $params,
        string $instrument,
        string $timeframe,
        string $source,
        string $environment,
        string $status,
        ?string $reason,
        array $quality,
        array $gate,
        array $series,
        array $values,
        int $candleCount,
        bool $overlay = false,
        array $outputs = ['value'],
    ): array {
        return [
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'indicator' => $indicator,
            'params' => $params,
            'overlay' => $overlay,
            'outputs' => $outputs,
            'timestamps_aligned_to' => 'candle_open_time',
            'source' => $source,
            'environment' => $environment,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'status' => $status,
            'reason' => $reason,
            'candle_count' => $candleCount,
            'point_count' => count($series),
            'cache_hit' => false,
            'freshness' => [
                'status' => $status === 'REFUSED' ? 'UNAVAILABLE' : 'FRESH',
            ],
            'quality' => [
                'status' => $quality['status'] ?? 'UNKNOWN',
                'score' => $quality['score'] ?? 0,
                'issues' => $quality['issues'] ?? [],
                'usable_for_analysis' => $status !== 'REFUSED',
            ],
            'gate' => [
                'allowed' => $status !== 'REFUSED',
                'reason' => $reason,
                'quality' => $quality,
                'phase' => 6,
                'execution' => false,
            ],
            'series' => $series,
            'values' => $values,
            'read_only' => true,
            'execution' => [
                'order_send' => false,
                'demo' => false,
                'live' => false,
            ],
        ];
    }

    private function touchHeartbeat(bool $ok): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'INDICATOR_ENGINE',
            'instance_id' => gethostname() ?: 'local',
            'status' => $ok ? 'ONLINE' : 'DEGRADED',
            'environment' => 'SIMULATION',
            'observed_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
            'details' => [
                'phase' => 6,
                'read_only' => true,
            ],
            'metadata' => [
                'order_send' => false,
                'consumes' => 'MarketDataEngineService::getClosedCandles',
            ],
        ]);
    }
}
