<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\Timeframe;
use App\Exceptions\TradingBridgeException;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use App\Models\MarketSnapshot;
use App\Models\MarketSymbol;
use App\Models\ServiceHeartbeat;
use App\Models\SystemEvent;
use App\Models\TradingInstrument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketDataEngineService
{
    private const MONITORED_KEY = 'market_data:monitored_symbols';

    public function __construct(
        private readonly TradingBridgeClient $bridge,
        private readonly MarketDataProvider $mockProvider,
        private readonly MarketSessionService $sessions,
        private readonly SpreadEngine $spreads,
        private readonly MarketDataQualityService $quality,
        private readonly QuoteCache $quoteCache,
        private readonly CandleGapDetector $gaps,
    ) {}

    /**
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
                $started = microtime(true);
                $payload = $this->bridge->get('market/snapshot', array_filter([
                    'symbols' => $symbols ? implode(',', $symbols) : null,
                    'candle_symbol' => strtoupper($candleSymbol),
                    'timeframe' => strtoupper($timeframe),
                    'candle_count' => $candleCount,
                ], fn ($value) => $value !== null && $value !== ''), cacheable: true);
                $latencyMs = (microtime(true) - $started) * 1000;
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $data['ingestion'] = 'BRIDGE';
                $data['meta'] = $payload['meta'] ?? null;
                $data = $this->enrichSnapshot($data, providerConnected: true, latencyMs: $latencyMs);
                $this->quoteCache->putMany($data['quotes'] ?? []);
                $this->touchHeartbeat('MT5', true);
                if ($persist) {
                    $this->persistSnapshot($data);
                }

                return $data;
            } catch (TradingBridgeException $exception) {
                $this->recordDisconnectEvent($exception->safeCode);
                $this->touchHeartbeat('MT5', false);
                if ($prefer === 'bridge') {
                    // NO SILENT FALLBACK — surface unavailable MT5 data.
                    throw $exception;
                }
            }
        }

        if ($prefer === 'bridge') {
            throw new TradingBridgeException('BRIDGE_NOT_CONFIGURED', 'The MT5 read-only bridge is not configured.');
        }

        $data = $this->simulationSnapshot($symbols, $candleSymbol, $timeframe, $candleCount);
        $data = $this->enrichSnapshot($data, providerConnected: true, latencyMs: 0.0);
        $this->quoteCache->putMany($data['quotes'] ?? []);
        $this->touchHeartbeat('MOCK', true);
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
     * @return array<string, mixed>|null
     */
    public function quote(string $symbol, string $prefer = 'auto'): ?array
    {
        $cached = $this->quoteCache->get($symbol);
        if ($cached !== null && $prefer !== 'bridge') {
            return $cached;
        }
        $quotes = $this->quotes([$symbol], $prefer);

        return $quotes[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candles(
        string $symbol,
        string $timeframe = 'M5',
        int $count = 100,
        string $prefer = 'auto',
        bool $closedOnly = false,
    ): array {
        $snapshot = $this->snapshot([$symbol], $symbol, $timeframe, $count, $prefer, persist: false);
        $bars = $snapshot['candles']['bars'] ?? [];
        if ($closedOnly) {
            $bars = array_values(array_filter($bars, fn (array $bar): bool => (bool) ($bar['is_closed'] ?? false)));
        }

        return $bars;
    }

    /**
     * Phase 6 input contract: closed candles only.
     *
     * @return list<array<string, mixed>>
     */
    public function getClosedCandles(string $symbol, string $timeframe, int $count = 100, string $prefer = 'auto'): array
    {
        return $this->candles($symbol, $timeframe, $count, $prefer, closedOnly: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function symbols(string $prefer = 'auto'): array
    {
        return $this->snapshot(prefer: $prefer, persist: false)['symbols'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $configured = $this->bridge->configured();
        $state = Cache::get('mt5_bridge:last_state', 'UNKNOWN');
        $latest = MarketSnapshot::query()->latest('generated_at')->first();
        $fresh = MarketQuote::query()->where('is_stale', false)->count();
        $stale = MarketQuote::query()->where('is_stale', true)->count();
        $heartbeat = ServiceHeartbeat::query()->where('service', 'MARKET_DATA_ENGINE')->latest('observed_at')->first();

        return [
            'engine' => 'MARKET_DATA_ENGINE',
            'phase' => 5,
            'read_only' => true,
            'provider_configured' => $configured,
            'provider_state' => $state,
            'monitored_symbols' => $this->monitoredSymbols(),
            'fresh_quotes' => $fresh,
            'stale_quotes' => $stale,
            'last_snapshot_at' => $latest?->generated_at,
            'last_quality_status' => $latest?->overall_quality_status,
            'heartbeat' => $heartbeat,
            'execution' => [
                'order_send' => false,
                'demo' => false,
                'live' => false,
            ],
            'extension_hooks' => [
                'phase_6_indicator_engine' => 'PENDING',
                'phase_7_strategies' => 'PENDING',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function symbolStatus(string $symbol, string $prefer = 'auto'): array
    {
        $quote = $this->quote($symbol, $prefer);
        $market = $this->sessions->marketStatus($symbol);
        $spread = $this->spreads->summary($symbol);

        return [
            'symbol' => strtoupper($symbol),
            'quote' => $quote,
            'market' => $market,
            'spread' => $spread,
            'sessions' => $this->sessions->sessions(),
            'read_only' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function monitoredSymbols(): array
    {
        $configured = Cache::get(self::MONITORED_KEY);
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strtoupper', $configured));
        }

        return TradingInstrument::query()->where('is_enabled', true)->orderBy('symbol')->pluck('symbol')->all()
            ?: ['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'NAS100', 'BTCUSD'];
    }

    /**
     * @param  list<string>  $symbols
     * @return list<string>
     */
    public function setMonitoredSymbols(array $symbols): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (string $symbol): string => strtoupper(trim($symbol)),
            $symbols
        ))));
        Cache::put(self::MONITORED_KEY, $normalized, now()->addDays(30));

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSymbols(string $prefer = 'auto'): array
    {
        if (! Cache::add('market_data:symbol_sync_lock', 1, 20)) {
            return ['status' => 'BUSY', 'message' => 'Symbol sync already running.'];
        }
        try {
            $rows = $this->symbols($prefer);
            $upserted = 0;
            foreach ($rows as $row) {
                MarketSymbol::query()->updateOrCreate(
                    [
                        'symbol' => $row['symbol'],
                        'source' => $row['source'] ?? 'MOCK',
                        'environment' => $row['environment'] ?? 'SIMULATION',
                    ],
                    [
                        'description' => $row['description'] ?? null,
                        'digits' => $row['digits'] ?? null,
                        'point' => $row['point'] ?? null,
                        'trade_tick_size' => $row['trade_tick_size'] ?? null,
                        'trade_tick_value' => $row['trade_tick_value'] ?? null,
                        'volume_min' => $row['volume_min'] ?? null,
                        'volume_max' => $row['volume_max'] ?? null,
                        'volume_step' => $row['volume_step'] ?? null,
                        'quality_score' => $row['quality']['score'] ?? 0,
                        'quality_status' => $row['quality']['status'] ?? 'UNKNOWN',
                        'quality_issues' => $row['quality']['issues'] ?? [],
                        'usable' => (bool) ($row['quality']['usable'] ?? false),
                        'payload' => $row,
                        'observed_at' => now(),
                    ]
                );
                $upserted++;
            }

            return ['status' => 'OK', 'upserted' => $upserted, 'symbols' => array_column($rows, 'symbol')];
        } finally {
            Cache::forget('market_data:symbol_sync_lock');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function backfill(string $symbol, string $timeframe, int $count, string $prefer = 'auto'): array
    {
        $count = max(1, min(500, $count));
        $bars = $this->candles($symbol, $timeframe, $count, $prefer);
        $source = $prefer === 'bridge' ? 'MT5' : 'MOCK';
        $environment = $prefer === 'bridge' ? 'DEMO' : 'SIMULATION';
        $written = 0;
        foreach ($bars as $bar) {
            MarketCandle::query()->updateOrCreate(
                [
                    'symbol' => $bar['symbol'],
                    'timeframe' => $bar['timeframe'],
                    'open_time' => Carbon::parse($bar['open_time']),
                    'source' => $bar['source'] ?? $source,
                    'environment' => $bar['environment'] ?? $environment,
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
            $written++;
        }
        $detected = $this->gaps->detect($bars, $timeframe);

        return [
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'requested' => $count,
            'written' => $written,
            'gaps' => $detected,
            'read_only' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function qualityCheck(string $prefer = 'auto'): array
    {
        $snapshot = $this->snapshot(prefer: $prefer, persist: false);
        $evaluation = $snapshot['data_quality'] ?? $this->quality->evaluate($snapshot['quotes'] ?? [], $snapshot['candles']['bars'] ?? []);

        return $this->quality->gate($evaluation);
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
        $requested = $symbols ?: $this->monitoredSymbols();
        $quotes = [];
        foreach ($requested as $symbol) {
            try {
                $quotes[] = $this->enrichMockQuote($this->mockProvider->getQuote($symbol));
            } catch (\Throwable) {
                // One broken symbol must not crash the batch.
                continue;
            }
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enrichSnapshot(array $data, bool $providerConnected, float $latencyMs): array
    {
        $timeframe = (string) ($data['candles']['timeframe'] ?? 'M5');
        $bars = $this->gaps->markClosed($data['candles']['bars'] ?? [], $timeframe);
        $gapRows = $this->gaps->detect($bars, $timeframe);
        $data['candles']['bars'] = $bars;
        $data['candles']['gaps'] = $gapRows;
        $data['candles']['closed_count'] = count(array_filter($bars, fn (array $bar): bool => (bool) ($bar['is_closed'] ?? false)));
        $data['candles']['forming_count'] = count($bars) - $data['candles']['closed_count'];

        $sessionBundle = $this->sessions->sessions();
        $data['sessions'] = $sessionBundle;
        foreach ($data['quotes'] ?? [] as $index => $quote) {
            $symbol = (string) ($quote['symbol'] ?? '');
            $digits = null;
            foreach ($data['symbols'] ?? [] as $symbolRow) {
                if (($symbolRow['symbol'] ?? null) === $symbol) {
                    $digits = isset($symbolRow['digits']) ? (int) $symbolRow['digits'] : null;
                    break;
                }
            }
            if (isset($quote['bid'], $quote['ask'])) {
                $spread = $this->spreads->calculate((string) $quote['bid'], (string) $quote['ask'], $digits);
                $data['quotes'][$index]['spread'] = $spread['raw'];
                $data['quotes'][$index]['spread_points'] = $spread['points'];
                $this->spreads->remember($symbol, $spread['raw']);
            }
            $data['quotes'][$index]['spread_summary'] = $this->spreads->summary($symbol);
            $data['quotes'][$index]['market'] = $this->sessions->marketStatus($symbol);
            $data['quotes'][$index]['change_percent'] = $this->changePercent($bars, $quote);
            $data['quotes'][$index]['daily_high'] = $this->dailyExtreme($bars, 'high');
            $data['quotes'][$index]['daily_low'] = $this->dailyExtreme($bars, 'low');
            $freshness = $data['quotes'][$index]['freshness']['status'] ?? 'UNKNOWN';
            if ($freshness === 'FRESH' && ($data['quotes'][$index]['freshness']['age_seconds'] ?? 0) > 5) {
                $data['quotes'][$index]['freshness']['status'] = 'AGING';
            }
        }

        $evaluation = $this->quality->evaluate($data['quotes'] ?? [], $bars, $providerConnected);
        $data['data_quality'] = $evaluation;
        $data['data_quality_gate'] = $this->quality->gate($evaluation);
        $data['metrics'] = [
            'latency_ms' => round($latencyMs, 3),
            'quote_cache_size' => count($this->quoteCache->all()),
            'gap_count' => count($gapRows),
        ];
        $data['summary']['overall_quality_score'] = $evaluation['score'];
        $data['summary']['overall_quality_status'] = $evaluation['status'];
        $data['summary']['usable_for_analysis'] = $evaluation['usable_for_analysis'];
        $data['summary']['extension_hooks'] = [
            'phase_6_indicator_engine' => 'PENDING',
            'phase_6_get_closed_candles' => 'READY',
            'phase_7_strategies' => 'PENDING',
        ];

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     * @param  array<string, mixed>  $quote
     */
    private function changePercent(array $bars, array $quote): ?string
    {
        if ($bars === [] || ! isset($quote['bid'])) {
            return null;
        }
        $first = $bars[0]['open'] ?? null;
        if ($first === null || (float) $first == 0.0) {
            return null;
        }

        return (string) round((((float) $quote['bid'] - (float) $first) / (float) $first) * 100, 4);
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     */
    private function dailyExtreme(array $bars, string $field): ?string
    {
        $values = [];
        foreach ($bars as $bar) {
            if (isset($bar[$field])) {
                $values[] = (float) $bar[$field];
            }
        }
        if ($values === []) {
            return null;
        }

        return (string) ($field === 'high' ? max($values) : min($values));
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
        $agingAfter = max(1.0, $staleAfter / 3);
        $isStale = $age > $staleAfter;
        if ($isStale) {
            $issues[] = 'STALE';
            $score -= 25;
        }
        $freshnessStatus = $isStale ? 'STALE' : ($age > $agingAfter ? 'AGING' : 'FRESH');

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
                'status' => $freshnessStatus,
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
            'is_closed' => true,
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

    private function touchHeartbeat(string $provider, bool $healthy): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'MARKET_DATA_ENGINE',
            'instance_id' => gethostname() ?: 'local',
            'status' => $healthy ? 'ONLINE' : 'DEGRADED',
            'environment' => $provider === 'MT5' ? 'DEMO' : 'SIMULATION',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => [
                'provider' => $provider,
                'monitored_symbols' => $this->monitoredSymbols(),
                'read_only' => true,
            ],
            'metadata' => [
                'provider' => $provider,
                'order_send' => false,
            ],
        ]);
    }

    private function recordDisconnectEvent(string $code): void
    {
        $last = Cache::get('market_data:last_disconnect_event');
        if ($last === $code) {
            return;
        }
        Cache::put('market_data:last_disconnect_event', $code, now()->addMinutes(5));
        SystemEvent::create([
            'level' => 'WARNING',
            'category' => 'MARKET_DATA',
            'message' => 'Market data provider unavailable.',
            'context' => ['code' => $code, 'silent_mock_fallback' => false],
            'occurred_at' => now(),
        ]);
    }
}
