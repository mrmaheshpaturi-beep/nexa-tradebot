<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\Timeframe;
use App\Exceptions\TradingBridgeException;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use App\Models\MarketSnapshot;
use App\Models\MarketSymbol;
use App\Models\TradingInstrument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketDataEngineService
{
    public function __construct(
        private readonly TradingBridgeClient $bridge,
        private readonly MarketDataProvider $mockProvider,
    ) {}

    /**
     * Build an aggregated market snapshot for UI and Phase 6 hooks.
     *
     * @param  list<string>|null  $symbols
     * @return array<string, mixed>
     */
    public function snapshot(
        ?array $symbols = null,
        string $candleSymbol = 'EURUSD',
        string $timeframe = 'M5',
        int $candleCount = 60,
        string $prefer = 'auto',
        bool $persist = true,
    ): array {
        $prefer = strtolower($prefer);
        $useBridge = $prefer === 'bridge' || ($prefer === 'auto' && $this->bridge->configured());

        if ($useBridge) {
            try {
                $payload = $this->bridge->get('market/snapshot', array_filter([
                    'symbols' => $symbols ? implode(',', $symbols) : null,
                    'candle_symbol' => strtoupper($candleSymbol),
                    'timeframe' => strtoupper($timeframe),
                    'candle_count' => $candleCount,
                ], fn ($value) => $value !== null && $value !== ''), cacheable: true);
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $data['ingestion'] = 'BRIDGE';
                $data['meta'] = $payload['meta'] ?? null;
                if ($persist) {
                    $this->persistSnapshot($data);
                }

                return $data;
            } catch (TradingBridgeException $exception) {
                if ($prefer === 'bridge') {
                    throw $exception;
                }
                // Fall through to simulation enrichment when auto.
            }
        }

        $data = $this->simulationSnapshot($symbols, $candleSymbol, $timeframe, $candleCount);
        if ($persist) {
            $this->persistSnapshot($data);
        }

        return $data;
    }

    /**
     * @param  list<string>|null  $symbols
     * @return list<array<string, mixed>>
     */
    public function quotes(?array $symbols = null, string $prefer = 'auto'): array
    {
        return $this->snapshot($symbols, prefer: $prefer, persist: false)['quotes'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candles(string $symbol, string $timeframe = 'M5', int $count = 100, string $prefer = 'auto'): array
    {
        $snapshot = $this->snapshot([$symbol], $symbol, $timeframe, $count, $prefer, persist: false);

        return $snapshot['candles']['bars'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function symbols(string $prefer = 'auto'): array
    {
        return $this->snapshot(prefer: $prefer, persist: false)['symbols'] ?? [];
    }

    /**
     * @param  list<string>|null  $symbols
     * @return array<string, mixed>
     */
    private function simulationSnapshot(
        ?array $symbols,
        string $candleSymbol,
        string $timeframe,
        int $candleCount,
    ): array {
        $instrumentSymbols = TradingInstrument::query()
            ->where('is_enabled', true)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
        $requested = $symbols ?: ($instrumentSymbols ?: ['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'NAS100', 'BTCUSD']);
        $quotes = [];
        foreach ($requested as $symbol) {
            $quotes[] = $this->enrichMockQuote($this->mockProvider->getQuote($symbol));
        }
        $symbolRows = [];
        foreach ($requested as $symbol) {
            $instrument = TradingInstrument::query()->where('symbol', $symbol)->first();
            $symbolRows[] = $this->normalizeMockSymbol($symbol, $instrument?->toArray() ?? []);
        }
        $tf = Timeframe::tryFrom(strtoupper($timeframe)) ?? Timeframe::M5;
        $bars = array_map(
            fn (array $candle): array => $this->enrichMockCandle($candle),
            $this->mockProvider->getCandles(strtoupper($candleSymbol), $tf, $candleCount)
        );
        $usable = collect($quotes)->where('quality.usable', true)->count();
        $stale = collect($quotes)->where('freshness.is_stale', true)->count();
        $score = $quotes === [] ? 0 : (int) round(collect($quotes)->avg('quality.score'));

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'read_only' => true,
            'environment' => 'SIMULATION',
            'source' => 'MOCK',
            'ingestion' => 'SIMULATION_PROVIDER',
            'bridge' => [
                'status' => $this->bridge->configured() ? 'AVAILABLE_UNUSED' : 'NOT_CONFIGURED',
                'mode' => 'MOCK',
                'stale' => false,
                'read_only' => true,
            ],
            'symbols' => $symbolRows,
            'quotes' => $quotes,
            'candles' => [
                'symbol' => strtoupper($candleSymbol),
                'timeframe' => $tf->value,
                'bars' => $bars,
            ],
            'summary' => [
                'symbol_count' => count($symbolRows),
                'quote_count' => count($quotes),
                'usable_quote_count' => $usable,
                'stale_quote_count' => $stale,
                'candle_count' => count($bars),
                'overall_quality_score' => $score,
                'overall_quality_status' => $this->qualityLabel($score),
                'extension_hooks' => [
                    'phase_6_indicator_engine' => 'PENDING',
                    'phase_7_strategies' => 'PENDING',
                ],
            ],
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function enrichMockQuote(array $quote): array
    {
        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];
        $issues = [];
        $score = 100;
        if ($ask < $bid) {
            $issues[] = 'INVERTED_SPREAD';
            $score -= 45;
        }
        $timestamp = Carbon::parse($quote['timestamp'])->utc();
        $age = max(0, now()->utc()->diffInMilliseconds($timestamp) / 1000);
        $staleAfter = (float) config('trading_bridge.market_stale_after_seconds', 15);
        $isStale = $age > $staleAfter;
        if ($isStale) {
            $issues[] = 'STALE';
            $score -= 25;
        }

        return [
            'symbol' => $quote['symbol'],
            'bid' => (string) $quote['bid'],
            'ask' => (string) $quote['ask'],
            'spread' => (string) $quote['spread'],
            'last' => (string) $quote['bid'],
            'volume' => null,
            'timestamp' => $timestamp->toIso8601String(),
            'received_at' => now()->utc()->toIso8601String(),
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
            'mode' => 'SIMULATION',
            'freshness' => [
                'status' => $isStale ? 'STALE' : 'FRESH',
                'age_seconds' => round($age, 3),
                'stale_after_seconds' => $staleAfter,
                'is_stale' => $isStale,
            ],
            'quality' => [
                'score' => max(0, $score),
                'status' => $this->qualityLabel(max(0, $score)),
                'issues' => $issues,
                'usable' => $score >= 50 && ! in_array('INVERTED_SPREAD', $issues, true),
            ],
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candle
     * @return array<string, mixed>
     */
    private function enrichMockCandle(array $candle): array
    {
        $issues = [];
        $score = 100;
        $open = (float) $candle['open'];
        $high = (float) $candle['high'];
        $low = (float) $candle['low'];
        $close = (float) $candle['close'];
        if ($high < max($open, $close) || $low > min($open, $close) || $high < $low) {
            $issues[] = 'OHLC_INCONSISTENT';
            $score -= 40;
        }

        return [
            'symbol' => $candle['symbol'],
            'timeframe' => $candle['timeframe'],
            'open_time' => $candle['open_time'],
            'close_time' => $candle['close_time'],
            'open' => (string) $candle['open'],
            'high' => (string) $candle['high'],
            'low' => (string) $candle['low'],
            'close' => (string) $candle['close'],
            'tick_volume' => (int) $candle['tick_volume'],
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
            'historical' => true,
            'freshness' => [
                'status' => 'FRESH',
                'age_seconds' => null,
                'stale_after_seconds' => (float) config('trading_bridge.market_stale_after_seconds', 15),
                'is_stale' => false,
            ],
            'quality' => [
                'score' => max(0, $score),
                'status' => $this->qualityLabel(max(0, $score)),
                'issues' => $issues,
                'usable' => $score >= 50 && ! in_array('OHLC_INCONSISTENT', $issues, true),
            ],
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @return array<string, mixed>
     */
    private function normalizeMockSymbol(string $symbol, array $instrument): array
    {
        return [
            'symbol' => $symbol,
            'description' => $instrument['display_name'] ?? $instrument['name'] ?? $symbol,
            'digits' => $instrument['digits'] ?? null,
            'point' => isset($instrument['point_size']) ? (string) $instrument['point_size'] : null,
            'trade_tick_size' => isset($instrument['tick_size']) ? (string) $instrument['tick_size'] : null,
            'trade_tick_value' => isset($instrument['tick_value']) ? (string) $instrument['tick_value'] : null,
            'volume_min' => isset($instrument['volume_min']) ? (string) $instrument['volume_min'] : null,
            'volume_max' => isset($instrument['volume_max']) ? (string) $instrument['volume_max'] : null,
            'volume_step' => isset($instrument['volume_step']) ? (string) $instrument['volume_step'] : null,
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
            'quality' => [
                'score' => 100,
                'status' => 'EXCELLENT',
                'issues' => [],
                'usable' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistSnapshot(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $source = (string) ($data['source'] ?? 'MOCK');
            $environment = (string) ($data['environment'] ?? 'SIMULATION');
            foreach ($data['symbols'] ?? [] as $symbol) {
                MarketSymbol::query()->updateOrCreate(
                    [
                        'symbol' => $symbol['symbol'],
                        'source' => $source,
                        'environment' => $environment,
                    ],
                    [
                        'description' => $symbol['description'] ?? null,
                        'digits' => $symbol['digits'] ?? null,
                        'point' => $symbol['point'] ?? null,
                        'trade_tick_size' => $symbol['trade_tick_size'] ?? null,
                        'trade_tick_value' => $symbol['trade_tick_value'] ?? null,
                        'volume_min' => $symbol['volume_min'] ?? null,
                        'volume_max' => $symbol['volume_max'] ?? null,
                        'volume_step' => $symbol['volume_step'] ?? null,
                        'quality_score' => $symbol['quality']['score'] ?? 0,
                        'quality_status' => $symbol['quality']['status'] ?? 'UNKNOWN',
                        'quality_issues' => $symbol['quality']['issues'] ?? [],
                        'usable' => (bool) ($symbol['quality']['usable'] ?? false),
                        'payload' => $symbol,
                        'observed_at' => now(),
                    ]
                );
            }
            foreach ($data['quotes'] ?? [] as $quote) {
                MarketQuote::query()->updateOrCreate(
                    [
                        'symbol' => $quote['symbol'],
                        'source' => $source,
                        'environment' => $environment,
                    ],
                    [
                        'bid' => $quote['bid'] ?? null,
                        'ask' => $quote['ask'] ?? null,
                        'spread' => $quote['spread'] ?? null,
                        'last' => $quote['last'] ?? null,
                        'volume' => $quote['volume'] ?? null,
                        'freshness_status' => $quote['freshness']['status'] ?? 'UNKNOWN',
                        'age_seconds' => $quote['freshness']['age_seconds'] ?? null,
                        'is_stale' => (bool) ($quote['freshness']['is_stale'] ?? false),
                        'quality_score' => $quote['quality']['score'] ?? 0,
                        'quality_status' => $quote['quality']['status'] ?? 'UNKNOWN',
                        'quality_issues' => $quote['quality']['issues'] ?? [],
                        'usable' => (bool) ($quote['quality']['usable'] ?? false),
                        'source_timestamp' => isset($quote['timestamp']) ? Carbon::parse($quote['timestamp']) : null,
                        'received_at' => isset($quote['received_at']) ? Carbon::parse($quote['received_at']) : now(),
                        'payload' => $quote,
                    ]
                );
            }
            $candleMeta = $data['candles'] ?? [];
            foreach ($candleMeta['bars'] ?? [] as $bar) {
                MarketCandle::query()->updateOrCreate(
                    [
                        'symbol' => $bar['symbol'],
                        'timeframe' => $bar['timeframe'],
                        'open_time' => Carbon::parse($bar['open_time']),
                        'source' => $source,
                        'environment' => $environment,
                    ],
                    [
                        'close_time' => isset($bar['close_time']) ? Carbon::parse($bar['close_time']) : null,
                        'open' => $bar['open'] ?? null,
                        'high' => $bar['high'] ?? null,
                        'low' => $bar['low'] ?? null,
                        'close' => $bar['close'] ?? null,
                        'tick_volume' => (int) ($bar['tick_volume'] ?? 0),
                        'quality_score' => $bar['quality']['score'] ?? 0,
                        'quality_status' => $bar['quality']['status'] ?? 'UNKNOWN',
                        'quality_issues' => $bar['quality']['issues'] ?? [],
                        'usable' => (bool) ($bar['quality']['usable'] ?? false),
                        'payload' => $bar,
                    ]
                );
            }
            $summary = $data['summary'] ?? [];
            MarketSnapshot::query()->create([
                'source' => $source,
                'environment' => $environment,
                'symbol_count' => (int) ($summary['symbol_count'] ?? 0),
                'quote_count' => (int) ($summary['quote_count'] ?? 0),
                'usable_quote_count' => (int) ($summary['usable_quote_count'] ?? 0),
                'stale_quote_count' => (int) ($summary['stale_quote_count'] ?? 0),
                'candle_count' => (int) ($summary['candle_count'] ?? 0),
                'overall_quality_score' => (int) ($summary['overall_quality_score'] ?? 0),
                'overall_quality_status' => (string) ($summary['overall_quality_status'] ?? 'UNKNOWN'),
                'candle_symbol' => $candleMeta['symbol'] ?? null,
                'candle_timeframe' => $candleMeta['timeframe'] ?? null,
                'payload' => $data,
                'generated_at' => isset($data['generated_at']) ? Carbon::parse($data['generated_at']) : now(),
            ]);
        });
    }

    private function qualityLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'EXCELLENT',
            $score >= 75 => 'GOOD',
            $score >= 50 => 'DEGRADED',
            $score >= 25 => 'POOR',
            default => 'INVALID',
        };
    }
}
