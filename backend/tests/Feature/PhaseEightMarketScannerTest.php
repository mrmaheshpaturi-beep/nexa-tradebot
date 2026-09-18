<?php

namespace Tests\Feature;

use App\Enums\TradingEnvironment;
use App\Models\ScannerRun;
use App\Models\SignalCandidate;
use App\Models\TradingStrategy;
use App\Services\ExecutionGate;
use App\Services\MarketScannerEngineService;
use App\Services\SignalOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseEightMarketScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedInstruments();
        config(['trading_bridge.service_token' => null, 'trading_bridge.base_url' => '']);
    }

    public function test_universe_and_health_advertise_phase_eight_without_execution(): void
    {
        $user = $this->userWithRole('ANALYST');

        $this->actingAs($user)
            ->getJson('/api/v1/scanner/universe')
            ->assertOk()
            ->assertJsonPath('data.phase', 8)
            ->assertJsonPath('data.execution.order_send', false);

        $this->actingAs($user)
            ->getJson('/api/v1/scanner/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 8)
            ->assertJsonPath('data.execution.order_send', false)
            ->assertJsonPath('data.execution.broker_routing', false)
            ->assertJsonPath('data.alerts.email', 'NOT_IMPLEMENTED');
    }

    public function test_manual_scan_creates_run_and_candidate_queue(): void
    {
        $user = $this->userWithRole('TRADER');

        $result = $this->actingAs($user)
            ->postJson('/api/v1/scanner/run', [
                'trigger' => 'MANUAL',
                'symbols' => ['EURUSD'],
                'timeframes' => ['M5'],
                'prefer' => 'simulation',
                'create_signals' => false,
                'create_candidates' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.phase', 8)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.execution.order_send', false)
            ->assertJsonPath('data.execution.broker_routing', false)
            ->json('data');

        $this->assertNotEmpty($result['matrix']);
        $this->assertSame('EURUSD', $result['matrix'][0]['symbol']);
        $this->assertDatabaseHas('scanner_runs', [
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'trigger' => 'MANUAL',
        ]);

        $queue = $this->actingAs($user)
            ->getJson('/api/v1/scanner/queue')
            ->assertOk()
            ->assertJsonPath('data.mode', 'CANDIDATE_QUEUE_NOT_ORDERS')
            ->assertJsonPath('data.execution.order_send', false)
            ->json('data');

        $this->assertIsArray($queue['candidates']);
        $this->assertStringContainsString('not broker orders', strtolower($queue['disclaimer']));
    }

    public function test_on_candle_close_scan_is_idempotent(): void
    {
        $user = $this->userWithRole('TRADER');
        $payload = [
            'trigger' => 'ON_CANDLE_CLOSE',
            'symbols' => ['EURUSD'],
            'timeframes' => ['M5'],
            'prefer' => 'simulation',
            'create_signals' => false,
            'create_candidates' => true,
        ];

        $first = $this->actingAs($user)->postJson('/api/v1/scanner/run', $payload)->assertOk()->json('data');
        $this->assertFalse($first['idempotent'] ?? true);
        $runId = $first['run']['id'];

        $second = $this->actingAs($user)->postJson('/api/v1/scanner/run', $payload)->assertOk()->json('data');
        $this->assertTrue($second['idempotent']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($runId, $second['run']['id']);
        $this->assertSame(1, ScannerRun::query()->where('user_id', $user->id)->count());
    }

    public function test_board_and_matrix_endpoints(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/scanner/run', [
            'trigger' => 'MANUAL',
            'symbols' => ['EURUSD', 'XAUUSD'],
            'timeframes' => ['M5'],
            'prefer' => 'simulation',
            'create_candidates' => true,
            'create_signals' => false,
        ])->assertOk();

        $this->actingAs($user)
            ->getJson('/api/v1/scanner/board')
            ->assertOk()
            ->assertJsonPath('data.phase', 8)
            ->assertJsonPath('data.execution.order_send', false);

        $this->actingAs($user)
            ->getJson('/api/v1/scanner/matrix?prefer=simulation&symbols=EURUSD&timeframes=M5')
            ->assertOk()
            ->assertJsonPath('data.phase', 8);
    }

    public function test_candidate_lifecycle_dismiss_invalidate_mark_simulate(): void
    {
        $user = $this->userWithRole('TRADER');
        $engine = app(MarketScannerEngineService::class);
        $engine->runScan($user, [
            'trigger' => 'MANUAL',
            'symbols' => ['EURUSD'],
            'timeframes' => ['M5'],
            'prefer' => 'simulation',
            'create_signals' => false,
            'create_candidates' => true,
        ]);

        $candidate = SignalCandidate::query()->where('user_id', $user->id)->first();
        if (! $candidate) {
            // Force-ingest a synthetic candidate for lifecycle coverage when series is idle.
            $run = ScannerRun::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
            $orch = app(SignalOrchestratorService::class);
            $orch->ingest($user, $run, [[
                'status' => 'SIGNAL',
                'direction' => 'BUY',
                'plugin_key' => 'ema_trend',
                'symbol' => 'EURUSD',
                'timeframe' => 'M5',
                'candle_close_key' => 'test-close',
                'raw_score' => 70,
                'confluence_score' => 70,
                'confluence' => ['score' => 70, 'direction' => 'BUY'],
                'configuration_version' => 1,
            ]]);
            $candidate = SignalCandidate::query()->where('user_id', $user->id)->firstOrFail();
        }

        $this->actingAs($user)
            ->postJson('/api/v1/scanner/candidates/'.$candidate->public_id.'/mark-simulate')
            ->assertOk()
            ->assertJsonPath('data.mt5_execution', false)
            ->assertJsonPath('data.simulate_target', 'SIMULATION_ONLY')
            ->assertJsonPath('data.execution.order_send', false);

        $this->assertTrue($candidate->fresh()->marked_for_simulate);

        $other = SignalCandidate::query()->create([
            'user_id' => $user->id,
            'scanner_run_id' => $candidate->scanner_run_id,
            'plugin_key' => 'rsi_momentum',
            'symbol' => 'EURUSD',
            'timeframe' => 'M5',
            'direction' => 'SELL',
            'status' => 'ACTIVE',
            'rank_score' => 60,
            'priority' => 100,
            'confluence_score' => 60,
            'candle_close_key' => 'test-close-2',
            'fingerprint' => hash('sha256', 'lifecycle-other'),
            'conflict_group' => 'EURUSD|M5',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/scanner/candidates/'.$other->public_id.'/dismiss')
            ->assertOk()
            ->assertJsonPath('data.status', 'DISMISSED');

        $third = SignalCandidate::query()->create([
            'user_id' => $user->id,
            'plugin_key' => 'breakout',
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'direction' => 'BUY',
            'status' => 'ACTIVE',
            'rank_score' => 55,
            'priority' => 100,
            'fingerprint' => hash('sha256', 'lifecycle-third'),
            'conflict_group' => 'XAUUSD|M5',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/scanner/candidates/'.$third->public_id.'/invalidate', ['reason' => 'TEST'])
            ->assertOk()
            ->assertJsonPath('data.status', 'INVALIDATED')
            ->assertJsonPath('data.invalidation_reason', 'TEST');
    }

    public function test_conflict_detection_flags_opposing_directions(): void
    {
        $user = $this->userWithRole('TRADER');
        $orch = app(SignalOrchestratorService::class);
        $run = ScannerRun::query()->create([
            'user_id' => $user->id,
            'run_key' => hash('sha256', 'conflict-test'),
            'trigger' => 'MANUAL',
            'status' => 'COMPLETED',
            'prefer' => 'simulation',
            'started_at' => now('UTC'),
            'finished_at' => now('UTC'),
        ]);

        $orch->ingest($user, $run, [
            [
                'status' => 'SIGNAL', 'direction' => 'BUY', 'plugin_key' => 'ema_trend',
                'symbol' => 'EURUSD', 'timeframe' => 'M5', 'candle_close_key' => 'c1',
                'raw_score' => 70, 'confluence_score' => 70, 'configuration_version' => 1,
            ],
            [
                'status' => 'SIGNAL', 'direction' => 'SELL', 'plugin_key' => 'rsi_momentum',
                'symbol' => 'EURUSD', 'timeframe' => 'M5', 'candle_close_key' => 'c1',
                'raw_score' => 65, 'confluence_score' => 65, 'configuration_version' => 1,
            ],
        ]);

        $flags = SignalCandidate::query()->where('user_id', $user->id)->get()
            ->flatMap(fn ($c) => collect($c->conflict_flags ?? [])->pluck('code'))
            ->unique()
            ->all();
        $this->assertContains('OPPOSING_DIRECTION', $flags);
    }

    public function test_config_upsert_and_alerts_foundation(): void
    {
        $user = $this->userWithRole('TRADER');

        $this->actingAs($user)
            ->putJson('/api/v1/scanner/configs', [
                'name' => 'Core FX',
                'symbols' => ['EURUSD', 'GBPUSD'],
                'timeframes' => ['M5', 'H1'],
                'trigger_mode' => 'ON_INTERVAL',
                'interval_seconds' => 300,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Core FX');

        $this->actingAs($user)->postJson('/api/v1/scanner/run', [
            'trigger' => 'MANUAL',
            'symbols' => ['EURUSD'],
            'timeframes' => ['M5'],
            'prefer' => 'simulation',
        ])->assertOk();

        $this->actingAs($user)
            ->getJson('/api/v1/scanner/alerts')
            ->assertOk()
            ->assertJsonPath('data.phase', 8)
            ->assertJsonPath('data.pipeline.sms', 'NOT_IMPLEMENTED');
    }

    public function test_system_status_includes_scanner_and_orchestrator(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.market_scanner.phase', 8)
            ->assertJsonPath('data.market_scanner.order_send', false)
            ->assertJsonPath('data.signal_orchestrator.broker_routing', false)
            ->assertJsonPath('data.alert_pipeline.status', 'FOUNDATION_READY')
            ->assertJsonPath('data.allow_demo_execution', false)
            ->assertJsonPath('data.allow_live_execution', false);
    }

    public function test_execution_gate_still_rejects_demo_live(): void
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
            $this->fail('DEMO should reject');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('environment', $e->errors());
        }
    }

    public function test_strategy_backed_scan_does_not_enable_auto_trading(): void
    {
        $user = $this->userWithRole('TRADER');
        TradingStrategy::query()->create([
            'user_id' => $user->id,
            'name' => 'P8 EMA',
            'slug' => 'p8-ema-'.uniqid(),
            'plugin_key' => 'ema_trend',
            'category' => 'TREND',
            'status' => 'ACTIVE',
            'mode' => 'SIGNAL_ONLY',
            'version' => 1,
            'minimum_signal_score' => 1,
            'symbols' => ['EURUSD'],
            'timeframes' => ['M5'],
            'parameters' => ['cooldown_minutes' => 1],
            'enabled' => true,
            'auto_trading_enabled' => false,
            'auto_simulation' => false,
            'created_by' => $user->id,
        ]);

        $result = $this->actingAs($user)->postJson('/api/v1/scanner/run', [
            'trigger' => 'MANUAL',
            'symbols' => ['EURUSD'],
            'timeframes' => ['M5'],
            'prefer' => 'simulation',
            'create_signals' => true,
            'create_candidates' => true,
        ])->assertOk()->json('data');

        $this->assertFalse($result['execution']['order_send']);
        $this->assertFalse($result['execution']['demo_execution']);
        $this->assertFalse($result['execution']['live_execution']);
    }

    public function test_no_order_send_in_phase_eight_sources(): void
    {
        $roots = [
            base_path('app/Services/MarketScannerEngineService.php'),
            base_path('app/Services/SignalOrchestratorService.php'),
            base_path('app/Services/AlertPipelineService.php'),
            base_path('app/Http/Controllers/Api/MarketScannerController.php'),
        ];
        foreach ($roots as $path) {
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/\border_send\s*\(/', $src);
            $this->assertStringNotContainsString('OrderSend', $src);
        }
    }
}
