<?php

namespace Tests\Feature;

use App\Services\IndicatorEngineService;
use App\Services\MarketDataEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseSixIndicatorEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedInstruments();
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);
    }

    public function test_catalog_lists_core_indicators(): void
    {
        $response = $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/indicators/catalog')
            ->assertOk()
            ->assertJsonPath('data.phase', 6)
            ->assertJsonPath('data.execution.order_send', false);

        $names = collect($response->json('data.indicators'))->pluck('name')->all();
        $this->assertSame(['SMA', 'EMA', 'RSI', 'MACD', 'ATR', 'BBANDS'], $names);
    }

    public function test_sma_series_from_closed_candles(): void
    {
        $response = $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/indicators/SMA/series?symbol=EURUSD&timeframe=M5&count=80&prefer=simulation&period=10')
            ->assertOk()
            ->assertJsonPath('data.indicator', 'SMA')
            ->assertJsonPath('data.instrument', 'EURUSD')
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.timestamps_aligned_to', 'candle_open_time')
            ->assertJsonPath('data.execution.order_send', false)
            ->assertJsonPath('data.read_only', true);

        $this->assertGreaterThan(0, $response->json('data.point_count'));
        $this->assertNotEmpty($response->json('data.series'));
        $this->assertArrayHasKey('value', $response->json('data.values'));
    }

    public function test_compute_and_batch_endpoints(): void
    {
        $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/indicators/compute', [
                'indicator' => 'EMA',
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'count' => 60,
                'prefer' => 'simulation',
                'params' => ['period' => 12],
            ])
            ->assertOk()
            ->assertJsonPath('data.indicator', 'EMA')
            ->assertJsonPath('data.status', 'READY');

        $batch = $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/indicators/batch', [
                'indicators' => ['RSI', 'MACD', 'ATR', 'BBANDS'],
                'symbol' => 'XAUUSD',
                'timeframe' => 'M15',
                'count' => 100,
                'prefer' => 'simulation',
            ])
            ->assertOk()
            ->json('data.results');

        $this->assertCount(4, $batch);
        foreach ($batch as $row) {
            $this->assertContains($row['status'], ['READY', 'DEGRADED']);
            $this->assertFalse($row['execution']['order_send']);
        }
    }

    public function test_quality_gate_refuses_bad_market_data(): void
    {
        $closed = [];
        for ($i = 0; $i < 30; $i++) {
            $closed[] = [
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'open_time' => now('UTC')->subMinutes(5 * (30 - $i))->toIso8601String(),
                'open' => '1.10000',
                'high' => '1.10050',
                'low' => '1.09950',
                'close' => '1.10020',
                'is_closed' => true,
            ];
        }

        $this->mock(MarketDataEngineService::class, function ($mock) use ($closed): void {
            $mock->shouldReceive('getClosedCandles')->andReturn($closed);
            $mock->shouldReceive('snapshot')->andReturn([
                'source' => 'MOCK',
                'environment' => 'SIMULATION',
                'quotes' => [],
                'data_quality' => [
                    'status' => 'BAD',
                    'score' => 10,
                    'issues' => ['STALE_QUOTE:EURUSD'],
                    'usable_for_analysis' => false,
                ],
            ]);
        });
        $this->app->forgetInstance(IndicatorEngineService::class);

        $this->actingAs($this->userWithRole('TRADER'))
            ->postJson('/api/v1/indicators/compute', [
                'indicator' => 'SMA',
                'symbol' => 'EURUSD',
                'prefer' => 'simulation',
                'count' => 40,
                'cache' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'REFUSED')
            ->assertJsonPath('data.gate.allowed', false)
            ->assertJsonPath('data.series', []);
    }

    public function test_bridge_prefer_does_not_silently_fallback(): void
    {
        config([
            'trading_bridge.base_url' => 'http://127.0.0.1:8765',
            'trading_bridge.service_token' => 'phase6-test-token',
        ]);
        Http::fake([
            '127.0.0.1:8765/*' => Http::response(['error' => ['code' => 'BRIDGE_UNAVAILABLE', 'message' => 'down']], 503),
        ]);

        $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/indicators/SMA/series?symbol=EURUSD&prefer=bridge')
            ->assertStatus(503)
            ->assertJsonPath('error.message', 'MT5 DATA UNAVAILABLE');
    }

    public function test_unknown_indicator_returns_422(): void
    {
        $this->actingAs($this->userWithRole('VIEWER'))
            ->getJson('/api/v1/indicators/FOO/series?symbol=EURUSD&prefer=simulation')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INDICATOR');
    }

    public function test_extension_hooks_advertise_ready_indicator_engine(): void
    {
        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/market/extension-hooks')
            ->assertOk()
            ->assertJsonPath('data.phase_6_indicator_engine.status', 'READY')
            ->assertJsonPath('data.phase_7_strategies.status', 'READY')
            ->assertJsonPath('data.execution.order_send', false);

        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/indicators/health')
            ->assertOk()
            ->assertJsonPath('data.engine', 'INDICATOR_ENGINE')
            ->assertJsonPath('data.execution.order_send', false);
    }

    public function test_engine_consumes_only_closed_candles_contract(): void
    {
        $market = $this->app->make(MarketDataEngineService::class);
        $closed = $market->getClosedCandles('EURUSD', 'M5', 50, 'simulation');
        $this->assertNotEmpty($closed);
        foreach ($closed as $bar) {
            $this->assertTrue((bool) ($bar['is_closed'] ?? false));
        }

        $engine = $this->app->make(IndicatorEngineService::class);
        $result = $engine->compute('BBANDS', 'EURUSD', 'M5', 80, ['period' => 20], 'simulation', false);
        $this->assertSame('READY', $result['status']);
        $this->assertArrayHasKey('upper', $result['values']);
    }
}
