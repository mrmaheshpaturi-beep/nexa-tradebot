<?php

namespace Tests\Feature;

use App\Enums\TradingEnvironment;
use App\Models\Signal;
use App\Models\TradingStrategy;
use App\Services\ConfluenceEngineService;
use App\Services\ExecutionGate;
use App\Services\StrategyEngineService;
use App\Services\TechnicalAnalysisEngine;
use App\Strategies\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSevenStrategyEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedInstruments();
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);
    }

    public function test_catalog_lists_twelve_plugins_without_upload(): void
    {
        $response = $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/strategy-engine/catalog')
            ->assertOk()
            ->assertJsonPath('data.phase', 7)
            ->assertJsonPath('data.upload_allowed', false)
            ->assertJsonPath('data.execution.order_send', false);

        $this->assertCount(12, $response->json('data.plugins'));
        $keys = collect($response->json('data.plugins'))->pluck('key')->all();
        $this->assertContains('ema_trend', $keys);
        $this->assertContains('mtf_trend', $keys);
        $this->assertContains('volatility_expansion', $keys);
    }

    public function test_technical_adapter_builds_snapshot_from_indicators(): void
    {
        $engine = app(TechnicalAnalysisEngine::class);
        $snap = $engine->snapshot('EURUSD', 'M5', 120, 'simulation');
        $this->assertContains($snap->status, ['READY', 'DEGRADED', 'REFUSED']);
        $this->assertNotSame('', $snap->candleCloseKey);
        $this->assertTrue($snap->adapter);
        $mtf = $engine->multiTimeframe('EURUSD', 'M5', ['M15'], 80, 'simulation');
        $this->assertArrayHasKey('M5', $mtf->frames);
    }

    public function test_evaluate_strategy_is_deterministic_and_idempotent(): void
    {
        $user = $this->userWithRole('TRADER');
        $strategy = $this->makeStrategy($user->id, 'ema_trend', minScore: 1);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/strategies/{$strategy->id}/evaluate", [
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'prefer' => 'simulation',
                'create_signal' => true,
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['execution']['order_send']);
        $this->assertFalse($first['auto_trading_enabled']);

        $second = $this->actingAs($user)
            ->postJson("/api/v1/strategies/{$strategy->id}/evaluate", [
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'prefer' => 'simulation',
                'create_signal' => true,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame($first['candle_close_key'], $second['candle_close_key']);
        if (($first['signal']['public_id'] ?? null) && ($second['signal']['public_id'] ?? null)) {
            $this->assertSame($first['signal']['public_id'], $second['signal']['public_id']);
        }
        $this->assertLessThanOrEqual(1, Signal::query()->where('trading_strategy_id', $strategy->id)->count());
    }

    public function test_disabled_strategy_is_gate_blocked(): void
    {
        $user = $this->userWithRole('TRADER');
        $strategy = $this->makeStrategy($user->id, 'rsi_momentum', enabled: false, status: 'DRAFT');

        $this->actingAs($user)
            ->postJson("/api/v1/strategies/{$strategy->id}/evaluate", [
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'prefer' => 'simulation',
            ])
            ->assertOk()
            ->assertJsonPath('data.gate.allowed', false)
            ->assertJsonPath('data.gate.reason', 'STRATEGY_DISABLED');
    }

    public function test_scanner_and_confluence_and_matrix(): void
    {
        $user = $this->userWithRole('ANALYST');

        $scan = $this->actingAs($user)
            ->postJson('/api/v1/strategy-engine/scan', [
                'symbol' => 'XAUUSD',
                'timeframe' => 'M5',
                'prefer' => 'simulation',
            ])
            ->assertOk()
            ->assertJsonPath('data.phase', 7)
            ->assertJsonPath('data.execution.order_send', false)
            ->json('data');

        $this->assertCount(12, $scan['evaluations']);
        $this->assertArrayHasKey('score', $scan['confluence']);
        $this->assertStringContainsString('not win probabilit', strtolower($scan['disclaimer']));

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-engine/confluence', [
                'symbol' => 'EURUSD',
                'timeframe' => 'M15',
                'prefer' => 'simulation',
            ])
            ->assertOk()
            ->assertJsonPath('data.execution.order_send', false);

        $this->actingAs($user)
            ->getJson('/api/v1/strategy-engine/matrix?prefer=simulation')
            ->assertOk()
            ->assertJsonPath('data.phase', 7);
    }

    public function test_confluence_avoids_double_counting_same_family(): void
    {
        $engine = app(ConfluenceEngineService::class);
        $rows = [
            [
                'plugin_key' => 'ema_trend',
                'status' => 'SIGNAL',
                'direction' => 'BUY',
                'raw_score' => 70,
                'evidence' => [['code' => 'A', 'family' => 'TREND_EMA', 'direction' => 'BUY', 'weight' => 1, 'detail' => 'a']],
            ],
            [
                'plugin_key' => 'ema_pullback',
                'status' => 'SIGNAL',
                'direction' => 'BUY',
                'raw_score' => 80,
                'evidence' => [['code' => 'B', 'family' => 'TREND_EMA', 'direction' => 'BUY', 'weight' => 1, 'detail' => 'b']],
            ],
        ];
        $merged = $engine->merge($rows);
        $families = collect($merged['families'])->pluck('family')->all();
        $this->assertSame(['TREND_EMA'], array_values(array_unique($families)));
        $this->assertLessThanOrEqual(100, $merged['score']);
    }

    public function test_signal_show_includes_explainability_not_ai(): void
    {
        $user = $this->userWithRole('TRADER');
        $strategy = $this->makeStrategy($user->id, 'breakout', minScore: 1);
        $this->actingAs($user)->postJson("/api/v1/strategies/{$strategy->id}/evaluate", [
            'symbol' => 'EURUSD', 'timeframe' => 'M5', 'prefer' => 'simulation',
        ])->assertOk();

        $signal = Signal::query()->where('user_id', $user->id)->latest('id')->first();
        if (! $signal) {
            $this->markTestSkipped('No actionable signal on this simulated series (deterministic idle).');
        }

        $detail = $this->actingAs($user)
            ->getJson('/api/v1/signals/'.$signal->public_id)
            ->assertOk()
            ->assertJsonPath('data.execution.order_send', false)
            ->json('data');
        $this->assertStringContainsString('not a win probability', strtolower((string) $detail['explainability']['disclaimer']));
    }

    public function test_execution_gate_rejects_demo_and_live(): void
    {
        $user = $this->userWithRole('TRADER');
        $account = $user->brokerAccounts()->create([
            'name' => 'Sim',
            'environment' => TradingEnvironment::Simulation,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
        ]);

        $gate = app(ExecutionGate::class);
        try {
            $gate->assertCanExecute(TradingEnvironment::Demo, $account);
            $this->fail('Expected ValidationException for DEMO');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('environment', $e->errors());
        }

        try {
            $gate->assertCanExecute(TradingEnvironment::Live, $account);
            $this->fail('Expected ValidationException for LIVE');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('environment', $e->errors());
        }
    }

    public function test_registry_has_exactly_twelve_strategies(): void
    {
        $registry = app(StrategyRegistry::class);
        $this->assertCount(12, $registry->all());
        foreach ($registry->all() as $plugin) {
            $this->assertNotSame('', $plugin->evidenceFamily());
            $this->assertIsArray($plugin->defaultParameters());
        }
    }

    public function test_strategy_enable_keeps_auto_trading_disabled(): void
    {
        $user = $this->userWithRole('TRADER');
        $strategy = $this->makeStrategy($user->id, 'macd_momentum', enabled: false, status: 'DRAFT');

        $this->actingAs($user)
            ->postJson("/api/v1/strategies/{$strategy->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.auto_trading_enabled', false);
    }

    public function test_performance_does_not_fabricate_win_rate(): void
    {
        $user = $this->userWithRole('TRADER');
        $strategy = $this->makeStrategy($user->id, 'market_structure');

        $perf = $this->actingAs($user)
            ->getJson("/api/v1/strategies/{$strategy->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.win_rate', null)
            ->json('data');
        $this->assertStringContainsString('No fabricated', (string) $perf['win_rate_note']);
    }

    public function test_health_endpoint(): void
    {
        $this->actingAs($this->userWithRole('ANALYST'))
            ->getJson('/api/v1/strategy-engine/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 7)
            ->assertJsonPath('data.plugins', 12)
            ->assertJsonPath('data.execution.order_send', false);
    }

    public function test_engine_service_scan_has_no_randomness(): void
    {
        $user = $this->userWithRole('TRADER');
        $engine = app(StrategyEngineService::class);
        $a = $engine->scan($user, 'EURUSD', 'M5', 'simulation');
        $b = $engine->scan($user, 'EURUSD', 'M5', 'simulation');
        $this->assertSame(
            collect($a['evaluations'])->pluck('status')->all(),
            collect($b['evaluations'])->pluck('status')->all(),
        );
        $this->assertSame($a['confluence']['score'], $b['confluence']['score']);
    }

    private function makeStrategy(
        int $userId,
        string $plugin,
        float $minScore = 55,
        bool $enabled = true,
        string $status = 'ACTIVE',
    ): TradingStrategy {
        return TradingStrategy::query()->create([
            'user_id' => $userId,
            'name' => 'Phase7 '.$plugin,
            'slug' => 'phase7-'.$plugin.'-'.uniqid(),
            'plugin_key' => $plugin,
            'category' => 'TREND',
            'description' => 'Phase 7 test strategy',
            'status' => $status,
            'mode' => 'SIGNAL_ONLY',
            'version' => 1,
            'minimum_signal_score' => $minScore,
            'symbols' => ['EURUSD', 'XAUUSD'],
            'timeframes' => ['M5', 'M15'],
            'sessions' => null,
            'parameters' => ['cooldown_minutes' => 1, 'expiry_minutes' => 60],
            'enabled' => $enabled,
            'auto_trading_enabled' => false,
            'auto_simulation' => false,
            'evaluation_mode' => 'ON_CANDLE_CLOSE',
            'higher_timeframes' => ['M15', 'H1'],
            'created_by' => $userId,
        ]);
    }
}
