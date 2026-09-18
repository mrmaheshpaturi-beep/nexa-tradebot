<?php

namespace Tests\Feature;

use App\Models\MarketQuote;
use App\Models\MarketSnapshot;
use App\Models\TradingInstrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseFiveMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedInstruments();
    }

    public function test_simulation_snapshot_includes_quality_and_persists(): void
    {
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);

        $response = $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/market/snapshot?prefer=simulation&candle_count=5&persist=1')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.environment', 'SIMULATION')
            ->assertJsonPath('data.source', 'MOCK')
            ->assertJsonPath('data.summary.extension_hooks.phase_6_indicator_engine', 'PENDING');

        $quotes = $response->json('data.quotes');
        $this->assertNotEmpty($quotes);
        $this->assertTrue($quotes[0]['quality']['usable']);
        $this->assertSame('FRESH', $quotes[0]['freshness']['status']);
        $this->assertDatabaseCount('market_snapshots', 1);
        $this->assertGreaterThan(0, MarketQuote::query()->count());
    }

    public function test_bridge_snapshot_is_used_when_configured(): void
    {
        config([
            'trading_bridge.base_url' => 'http://127.0.0.1:8765',
            'trading_bridge.service_token' => 'phase5-test-token',
        ]);

        Http::fake([
            '127.0.0.1:8765/v1/market/snapshot*' => Http::response([
                'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'read_only' => true,
                    'environment' => 'DEMO',
                    'source' => 'MOCK_MT5',
                    'bridge' => ['status' => 'CONNECTED', 'mode' => 'MOCK', 'stale' => false, 'read_only' => true],
                    'symbols' => [[
                        'symbol' => 'EURUSD',
                        'description' => 'Euro / US Dollar',
                        'digits' => 5,
                        'quality' => ['score' => 100, 'status' => 'EXCELLENT', 'issues' => [], 'usable' => true],
                    ]],
                    'quotes' => [[
                        'symbol' => 'EURUSD',
                        'bid' => '1.10000',
                        'ask' => '1.10020',
                        'spread' => '0.00020',
                        'timestamp' => now()->toIso8601String(),
                        'received_at' => now()->toIso8601String(),
                        'freshness' => ['status' => 'FRESH', 'age_seconds' => 0.1, 'stale_after_seconds' => 15, 'is_stale' => false],
                        'quality' => ['score' => 100, 'status' => 'EXCELLENT', 'issues' => [], 'usable' => true],
                    ]],
                    'candles' => [
                        'symbol' => 'EURUSD',
                        'timeframe' => 'M5',
                        'bars' => [[
                            'symbol' => 'EURUSD',
                            'timeframe' => 'M5',
                            'open_time' => now()->subMinutes(5)->toIso8601String(),
                            'close_time' => now()->toIso8601String(),
                            'open' => '1.10000',
                            'high' => '1.10040',
                            'low' => '1.09980',
                            'close' => '1.10020',
                            'tick_volume' => 120,
                            'quality' => ['score' => 100, 'status' => 'EXCELLENT', 'issues' => [], 'usable' => true],
                        ]],
                    ],
                    'summary' => [
                        'symbol_count' => 1,
                        'quote_count' => 1,
                        'usable_quote_count' => 1,
                        'stale_quote_count' => 0,
                        'candle_count' => 1,
                        'overall_quality_score' => 100,
                        'overall_quality_status' => 'EXCELLENT',
                        'extension_hooks' => [
                            'phase_6_indicator_engine' => 'PENDING',
                            'phase_7_strategies' => 'PENDING',
                        ],
                    ],
                ],
                'meta' => [
                    'environment' => 'DEMO',
                    'source_timestamp' => now()->toIso8601String(),
                    'received_timestamp' => now()->toIso8601String(),
                    'freshness' => 'FRESH',
                    'adapter_version' => '0.2.0',
                    'correlation_id' => 'phase5-test',
                ],
            ]),
        ]);

        $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/market/snapshot?prefer=bridge&persist=1')
            ->assertOk()
            ->assertJsonPath('data.ingestion', 'BRIDGE')
            ->assertJsonPath('data.environment', 'DEMO')
            ->assertJsonPath('data.quotes.0.symbol', 'EURUSD');

        $this->assertSame(1, MarketSnapshot::query()->where('environment', 'DEMO')->count());
    }

    public function test_quotes_candles_and_extension_hooks_endpoints(): void
    {
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/quotes?prefer=simulation&symbols=EURUSD')
            ->assertOk()
            ->assertJsonPath('data.0.symbol', 'EURUSD');

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/candles/EURUSD?prefer=simulation&count=3')
            ->assertOk();

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/extension-hooks')
            ->assertOk()
            ->assertJsonPath('data.market_data_engine', 'READY')
            ->assertJsonPath('data.execution.order_send', false);
    }

    public function test_bridge_prefer_does_not_silently_fallback_to_mock(): void
    {
        config([
            'trading_bridge.base_url' => 'http://127.0.0.1:8765',
            'trading_bridge.service_token' => 'phase5-test-token',
        ]);
        Http::fake([
            '127.0.0.1:8765/*' => Http::response(['error' => ['code' => 'BRIDGE_UNAVAILABLE', 'message' => 'down']], 503),
        ]);

        $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/market/snapshot?prefer=bridge')
            ->assertStatus(503)
            ->assertJsonPath('error.message', 'MT5 DATA UNAVAILABLE');
    }

    public function test_sessions_health_quality_and_closed_candles(): void
    {
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/sessions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['sessions', 'active']]);

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/health')
            ->assertOk()
            ->assertJsonPath('data.engine', 'MARKET_DATA_ENGINE')
            ->assertJsonPath('data.execution.order_send', false);

        $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/market/quality-check', ['prefer' => 'simulation'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['allowed', 'quality']]);

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/candles/EURUSD/closed?prefer=simulation&count=5')
            ->assertOk();
    }

    public function test_backfill_and_symbol_sync_are_permission_scoped(): void
    {
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);

        $this->actingAs($this->userWithRole('VIEWER'))
            ->postJson('/api/v1/market/backfill', ['symbol' => 'EURUSD', 'timeframe' => 'M5', 'count' => 10, 'prefer' => 'simulation'])
            ->assertForbidden();

        $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/market/backfill', ['symbol' => 'EURUSD', 'timeframe' => 'M5', 'count' => 10, 'prefer' => 'simulation'])
            ->assertOk()
            ->assertJsonPath('data.symbol', 'EURUSD');

        $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/market/symbols/sync', ['prefer' => 'simulation'])
            ->assertOk()
            ->assertJsonPath('data.status', 'OK');
    }

    public function test_unauthenticated_market_snapshot_is_rejected(): void
    {
        $this->getJson('/api/v1/market/snapshot')->assertUnauthorized();
    }

    private function seedInstruments(): void
    {
        foreach ([
            ['EURUSD', 'Euro / US Dollar', 'FOREX', 'EUR', 'USD', 5, 0.00001],
            ['XAUUSD', 'Gold / US Dollar', 'METAL', 'XAU', 'USD', 2, 0.01],
        ] as [$symbol, $name, $asset, $base, $quote, $digits, $point]) {
            TradingInstrument::query()->firstOrCreate(['symbol' => $symbol], [
                'name' => $name,
                'display_name' => $name,
                'asset_class' => $asset,
                'currency_base' => $base,
                'currency_quote' => $quote,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'digits' => $digits,
                'point_size' => $point,
                'contract_size' => 100000,
                'tick_size' => $point,
                'tick_value' => 1,
                'volume_min' => 0.01,
                'volume_max' => 100,
                'volume_step' => 0.01,
                'minimum_volume' => 0.01,
                'maximum_volume' => 100,
                'step_volume' => 0.01,
                'is_enabled' => true,
            ]);
        }
    }
}
