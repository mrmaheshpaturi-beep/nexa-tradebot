<?php

namespace Tests\Feature;

use App\Analytics\AnalyticsEngineService;
use App\Analytics\MetricsCalculator;
use App\Backtest\BacktestEngineService;
use App\Backtest\BacktestJobQueue;
use App\Backtest\IntrabarPolicy;
use App\Enums\CloseReason;
use App\Enums\OrderDirection;
use App\Enums\TradingEnvironment;
use App\Models\AnalyticsDataset;
use App\Models\BacktestRun;
use App\Models\ManagedPosition;
use App\Models\TradeSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwelveAnalyticsBacktestTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function candles(int $n = 200, float $start = 1.1000): array
    {
        $out = [];
        $px = $start;
        for ($i = 0; $i < $n; $i++) {
            $drift = (($i % 17) - 8) * 0.00008;
            $open = $px;
            $close = $px + $drift;
            $high = max($open, $close) + 0.00025;
            $low = min($open, $close) - 0.00025;
            $t0 = strtotime('2025-01-01T00:00:00Z') + $i * 300;
            $out[] = [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'open_time' => date('c', $t0),
                'close_time' => date('c', $t0 + 299),
            ];
            $px = $close;
        }

        return $out;
    }

    public function test_metrics_win_rate_na_without_completed_outcomes(): void
    {
        $calc = new MetricsCalculator;
        $m = $calc->compute([]);
        $this->assertNull($m['core']['win_rate']);
        $this->assertSame('N/A_INSUFFICIENT_COMPLETED_OUTCOMES', $m['core']['win_rate_status']);
        $this->assertTrue($m['deterministic']);
        $this->assertNull($m['random_seed_used']);
    }

    public function test_metrics_deterministic_same_input(): void
    {
        $rows = [
            ['realized_pnl' => 10.0, 'r_multiple' => 1.2, 'mae' => -0.1, 'mfe' => 0.3],
            ['realized_pnl' => -5.0, 'r_multiple' => -0.5, 'mae' => -0.2, 'mfe' => 0.1],
            ['realized_pnl' => 8.0, 'r_multiple' => 0.8, 'mae' => -0.05, 'mfe' => 0.2],
        ];
        $calc = new MetricsCalculator;
        $a = $calc->compute($rows);
        $b = $calc->compute($rows);
        $this->assertSame($a, $b);
        $this->assertEqualsWithDelta(2 / 3, $a['core']['win_rate'], 1e-9);
    }

    public function test_analytics_dataset_and_snapshot_from_trade_summaries(): void
    {
        $user = $this->userWithRole('TRADER');
        $account = \App\Models\BrokerAccount::query()->firstOrCreate(
            ['user_id' => $user->id, 'broker_login' => '912001'],
            [
                'name' => 'Demo',
                'environment' => TradingEnvironment::Demo,
                'status' => 'READY',
                'is_enabled' => true,
                'currency' => 'USD',
                'leverage' => 100,
                'broker_server' => 'Nexa-Demo',
                'verified_trade_mode' => 'DEMO',
                'demo_verified_at' => now(),
            ]
        );
        $position = ManagedPosition::query()->create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'symbol' => 'EURUSD',
            'broker_symbol' => 'EURUSD',
            'direction' => OrderDirection::Buy,
            'entry_price' => 1.1,
            'initial_volume' => 0.1,
            'current_volume' => 0,
            'management_status' => \App\Enums\ManagementStatus::Closed,
            'ownership' => \App\Enums\PositionOwnership::NexaManaged,
            'environment' => TradingEnvironment::Demo,
            'realized_profit' => 12.5,
            'mae' => -0.0005,
            'mfe' => 0.0012,
            'r_multiple' => 1.1,
            'break_even_applied' => true,
            'trailing_active' => true,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'metadata' => [
                'session' => 'LONDON',
                'timeframe' => 'M5',
                'regime' => 'TREND',
                'strategy_key' => 'ema_trend',
                'strategy_version' => '1',
                'spread_cost' => 0.5,
                'commission' => 0.7,
                'slippage' => 0.2,
                'swap' => 0.0,
            ],
        ]);
        TradeSummary::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $user->id,
            'symbol' => 'EURUSD',
            'direction' => OrderDirection::Buy,
            'entry_price' => 1.1,
            'exit_price' => 1.1012,
            'initial_volume' => 0.1,
            'closed_volume' => 0.1,
            'realized_pnl' => 12.5,
            'mae' => -0.0005,
            'mfe' => 0.0012,
            'r_multiple' => 1.1,
            'close_reason' => CloseReason::TakeProfit,
            'break_even_applied' => true,
            'trailing_used' => true,
            'partials_count' => 0,
            'timeline' => [],
            'finalized' => true,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'finalized_at' => now(),
        ]);

        $engine = app(AnalyticsEngineService::class);
        $dataset = $engine->buildDataset($user, 'test-ds', [], 'DEMO');
        $this->assertSame(1, $dataset->row_count);
        $this->assertSame(64, strlen($dataset->content_hash));
        $snap = $engine->snapshot($user, $dataset, 't1');
        $this->assertTrue($snap->immutable);
        $this->assertSame(1, $snap->metrics['core']['completed_trades']);
        $this->assertEqualsWithDelta(1.0, $snap->metrics['core']['win_rate'], 1e-9);

        $this->actingAs($user)
            ->getJson('/api/v1/analytics/dashboard')
            ->assertOk()
            ->assertJsonPath('data.auto_promote_strategies', false)
            ->assertJsonPath('data.live_execution', 'HARD_BLOCKED');
    }

    public function test_backtest_deterministic_and_lineage(): void
    {
        $user = $this->userWithRole('TRADER');
        $engine = app(BacktestEngineService::class);
        $candles = $this->candles(180);
        $a = $engine->simulate($candles, 'ema_trend', ['warmup' => 60], [], [], [], IntrabarPolicy::OHLC_PATH);
        $b = $engine->simulate($candles, 'ema_trend', ['warmup' => 60], [], [], [], IntrabarPolicy::OHLC_PATH);
        $this->assertSame($a['metrics'], $b['metrics']);
        $this->assertSame($a['trades'], $b['trades']);
        $this->assertTrue($a['metrics']['deterministic']);
        $this->assertArrayHasKey('intrabar_policy', $a['metrics']);

        $snap = $engine->createDataSnapshot($user, 'EURUSD', 'M5', $candles);
        $run = $engine->queueRun($user, $snap, 'ema_trend', ['warmup' => 60], [], [], [], IntrabarPolicy::OHLC_PATH, 'SINGLE', 42);
        $engine->processNextJobs(3);
        $run = $run->fresh();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(TradingEnvironment::Backtest, $run->environment);
        $this->assertNotEmpty($run->lineage_hash);
        $this->assertSame('BACKTEST', $run->lineage['environment']);
        $this->assertSame(0, $run->lineage['broker_changing_calls']);
        $this->assertFalse($run->lineage['auto_promote']);
    }

    public function test_walk_forward_monte_carlo_optimization_portfolio(): void
    {
        $user = $this->userWithRole('TRADER');
        $engine = app(BacktestEngineService::class);
        $candles = $this->candles(240);
        $snap = $engine->createDataSnapshot($user, 'EURUSD', 'M5', $candles);

        foreach (['WALK_FORWARD', 'MONTE_CARLO', 'OPTIMIZATION', 'PORTFOLIO'] as $kind) {
            $run = $engine->queueRun(
                $user,
                $snap,
                'ema_trend',
                [
                    'warmup' => 50,
                    'walk_forward_folds' => 3,
                    'monte_carlo_paths' => 10,
                    'portfolio_strategies' => ['ema_trend', 'rsi_momentum'],
                    'optimize' => [
                        'sl_atr_mult' => ['min' => 1.0, 'max' => 1.5, 'step' => 0.5],
                        'tp_atr_mult' => ['min' => 2.0, 'max' => 2.5, 'step' => 0.5],
                    ],
                ],
                [],
                [],
                [],
                IntrabarPolicy::OHLC_PATH,
                $kind,
                7,
            );
            $engine->processNextJobs(5);
            $run = $run->fresh();
            $this->assertSame('COMPLETED', $run->status, $kind);
            $this->assertSame('BACKTEST', $run->environment->value);
        }

        $mc = BacktestRun::query()->where('run_kind', 'MONTE_CARLO')->latest('id')->first();
        $this->assertNotNull($mc->metrics['monte_carlo']['base_seed'] ?? null);
        $this->assertTrue($mc->metrics['monte_carlo']['reproducible']);
    }

    public function test_queue_bounded(): void
    {
        $user = $this->userWithRole('TRADER');
        $queue = app(BacktestJobQueue::class);
        for ($i = 0; $i < BacktestJobQueue::MAX_QUEUED_PER_USER; $i++) {
            $queue->enqueue($user, 'NOOP', ['i' => $i]);
        }
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $queue->enqueue($user, 'NOOP', ['overflow' => true]);
    }

    public function test_backtest_demo_comparison_and_promote_refused(): void
    {
        $user = $this->userWithRole('TRADER');
        $analytics = app(AnalyticsEngineService::class);
        $backtest = app(BacktestEngineService::class);

        // Empty dataset snapshot for DEMO side
        $dataset = AnalyticsDataset::query()->create([
            'user_id' => $user->id,
            'name' => 'empty',
            'source_environment' => 'DEMO',
            'status' => 'READY',
            'row_count' => 0,
            'filters' => [],
            'fingerprint' => ['empty' => true],
            'content_hash' => hash('sha256', 'empty'),
            'built_at' => now(),
        ]);
        $demoSnap = $analytics->snapshot($user, $dataset, 'demo');

        $candles = $this->candles(120);
        $dataSnap = $backtest->createDataSnapshot($user, 'EURUSD', 'M5', $candles);
        $run = $backtest->queueRun($user, $dataSnap, 'ema_trend', ['warmup' => 40], [], [], [], IntrabarPolicy::OHLC_PATH, 'SINGLE', 1);
        $backtest->processNextJobs(2);
        $run = $run->fresh();

        $cmp = $analytics->compareBacktestToDemo($user, $run, $demoSnap);
        $this->assertSame('BACKTEST', $cmp->backtest_label);
        $this->assertSame('DEMO', $cmp->demo_label);
        $this->assertTrue($cmp->comparison['separation']['labels_distinct']);
        $this->assertFalse($cmp->comparison['auto_promote']);

        $this->actingAs($user)
            ->postJson('/api/v1/analytics/promote', [])
            ->assertForbidden();
    }

    public function test_api_health_rbac_and_exports(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)
            ->getJson('/api/v1/analytics/health')
            ->assertOk()
            ->assertJsonPath('data.broker_changing_calls', 0)
            ->assertJsonPath('data.auto_promote_strategies', false);

        $viewer = $this->userWithRole('VIEWER');
        $this->actingAs($viewer)
            ->postJson('/api/v1/backtest/runs', [])
            ->assertForbidden();

        $engine = app(BacktestEngineService::class);
        $snap = $engine->createDataSnapshot($user, 'EURUSD', 'M5', $this->candles(100));
        $run = $engine->queueRun($user, $snap, 'ema_trend', ['warmup' => 40]);
        $engine->processNextJobs(2);
        $run = $run->fresh();

        $this->actingAs($user)
            ->get('/api/v1/backtest/runs/'.$run->public_id.'/export?format=csv')
            ->assertOk();
    }

    public function test_system_status_includes_phase_twelve_engines(): void
    {
        $user = $this->userWithRole('VIEWER');
        $this->actingAs($user)
            ->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.analytics_engine.status', 'READY')
            ->assertJsonPath('data.backtest_engine.environment', 'BACKTEST')
            ->assertJsonPath('data.allow_live_execution', false);
    }

    public function test_phase12_source_has_zero_broker_changing_calls(): void
    {
        $roots = [
            app_path('Analytics'),
            app_path('Backtest'),
        ];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/\border_send\s*\(/', $src, $file->getPathname());
                $this->assertStringNotContainsString('DemoBridgeClient', $src, $file->getPathname());
                $this->assertStringNotContainsString('TradingBridgeDemoClient', $src, $file->getPathname());
                $this->assertStringNotContainsString('authorized_order_send', $src, $file->getPathname());
                $this->assertStringNotContainsString('MetaTrader5', $src, $file->getPathname());
            }
        }
    }
}
