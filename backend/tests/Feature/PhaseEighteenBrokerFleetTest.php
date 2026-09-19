<?php

namespace Tests\Feature;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\StrategyLifecycleState;
use App\Enums\TradingEnvironment;
use App\Fleet\BrokerFleetService;
use App\Fleet\Connectors\Mt5FleetAdapter;
use App\Fleet\Support\FleetSafety;
use App\Models\ApplicationSetting;
use App\Models\BrokerAccount;
use App\Models\ExecutionRoute;
use App\Models\FleetAccount;
use App\Models\GovernedStrategyVersion;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\TradingNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseEighteenBrokerFleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_matrix_and_health(): void
    {
        $health = app(BrokerFleetService::class)->healthPayload();
        $this->assertSame(18, $health['phase']);
        $this->assertSame(FleetSafety::FLEET_VERSION, $health['fleet_version']);
        $this->assertFalse($health['live_auto_exists']);
        $this->assertFalse($health['copy_trading']);
        $this->assertFalse($health['ai_may_route']);
        $this->assertFalse($health['ai_may_allocate']);
        $this->assertFalse($health['ai_may_change_risk']);
        $this->assertSame(0, $health['new_order_send_paths']);
        $this->assertTrue($health['phase_10_sole_execution']);
        $this->assertFalse($health['connector']['order_send']);
        $this->assertTrue($health['connector']['routes_into_phase_10']);
        $this->assertFalse($health['connector']['duplicate_execution_engine']);
    }

    public function test_provider_account_fingerprint_connection_capability_models(): void
    {
        $user = $this->userWithRole('TRADER');
        [$provider, $conn, $fleet] = $this->seedFleet($user);

        $this->assertNotNull($provider->public_id);
        $this->assertNotNull($conn->public_id);
        $this->assertNotNull($fleet->public_id);
        $this->assertDatabaseHas('broker_capabilities', [
            'fleet_account_id' => $fleet->id,
            'capability_key' => 'DEMO_EXECUTE_VIA_PHASE10',
        ]);

        $verified = app(BrokerFleetService::class)->verifyAccountEnvironment($user, $fleet, true);
        $this->assertSame('DEMO', $verified['trade_mode']);
        $this->assertDatabaseHas('account_fingerprints', [
            'fleet_account_id' => $fleet->id,
            'is_current' => true,
        ]);
    }

    public function test_live_unknown_hard_blocked_and_fingerprint_safe_mode(): void
    {
        $user = $this->userWithRole('TRADER');
        $liveBroker = $user->brokerAccounts()->create([
            'name' => 'Live',
            'environment' => TradingEnvironment::Live,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
        ]);
        $provider = app(BrokerFleetService::class)->registerProvider($user, [
            'code' => 'MT5X',
            'name' => 'X',
            'platform' => 'MT5',
        ]);
        try {
            app(BrokerFleetService::class)->registerFleetAccount($user, [
                'provider_public_id' => $provider->public_id,
                'broker_account_public_id' => $liveBroker->public_id,
                'environment' => 'LIVE',
                'display_name' => 'Nope',
            ]);
            $this->fail('LIVE registration must fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        [, , $fleet] = $this->seedFleet($user, '900001');
        app(BrokerFleetService::class)->verifyAccountEnvironment($user, $fleet, true);

        // Currency change alters fingerprint while bridge login still matches — SAFE_MODE
        $fleet->forceFill(['currency' => 'EUR'])->save();
        try {
            app(BrokerFleetService::class)->verifyAccountEnvironment($user, $fleet->fresh(), true);
            $this->fail('fingerprint mismatch must fail');
        } catch (ValidationException $e) {
            $this->assertTrue($fleet->fresh()->safe_mode);
            $this->assertNotEmpty($e->errors()['fingerprint'] ?? $e->errors());
        }
    }

    public function test_mt5_adapter_has_no_order_send_duplicate(): void
    {
        $boundary = app(Mt5FleetAdapter::class)->executionBoundary();
        $this->assertFalse($boundary['order_send']);
        $this->assertTrue($boundary['routes_into_phase_10']);
        $this->assertFalse($boundary['duplicate_execution_engine']);
        $this->assertSame(FleetSafety::ORDER_SEND_LOCATION, $boundary['order_send_location']);
    }

    public function test_terminal_registry_supervisor_isolation(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $terminal = app(BrokerFleetService::class)->registerTerminal($user, $fleet, 'mock-node-1');
        $this->assertStringContainsString($fleet->public_id, $terminal->isolation_key);
        $supervised = app(BrokerFleetService::class)->superviseTerminal($user, $terminal);
        $this->assertSame('HEALTHY', $supervised->supervisor_state);

        app(BrokerFleetService::class)->enterSafeMode($fleet, 'TEST');
        $blocked = app(BrokerFleetService::class)->superviseTerminal($user, $terminal->fresh());
        $this->assertSame('SAFE_MODE', $blocked->supervisor_state);
    }

    public function test_instruments_mapping_and_spec_freshness(): void
    {
        $user = $this->userWithRole('TRADER');
        [$provider] = $this->seedFleet($user);
        $mapped = app(BrokerFleetService::class)->mapBrokerInstrument($user, [
            'provider_public_id' => $provider->public_id,
            'canonical_symbol' => 'EURUSD',
            'broker_symbol' => 'EURUSDm',
            'fresh_minutes' => 15,
            'spec' => ['digits' => 5],
        ]);
        $this->assertTrue($mapped['spec_fresh']);
        $this->assertSame('EURUSD', $mapped['canonical']->symbol);

        $mapped['broker_instrument']->forceFill(['spec_fresh_until' => now()->subMinute()])->save();
        $freshness = app(BrokerFleetService::class)->refreshInstrumentSpecs($user);
        $this->assertSame(1, $freshness['stale']);
    }

    public function test_portfolios_versioned_allocation_and_approved_assignments(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $portfolio = $svc->createPortfolio($user, ['name' => 'Core', 'base_currency' => 'USD']);
        $svc->addMembership($user, $portfolio, $fleet, 'PRIMARY');

        $plan = $svc->activateAllocation($user, $portfolio, [$fleet->public_id => 1.0]);
        $this->assertSame(1, $plan->version);
        $this->assertSame('ACTIVE', $plan->status);
        $replay = $svc->activateAllocation($user, $portfolio, [$fleet->public_id => 1.0]);
        $this->assertSame(2, $replay->version);
        $this->assertSame($plan->plan_hash, $replay->plan_hash);

        try {
            $svc->activateAllocation($user, $portfolio, [$fleet->public_id => 1.0], true);
            $this->fail('AI allocate must fail');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        GovernedStrategyVersion::query()->create([
            'user_id' => $user->id,
            'strategy_key' => 'ema_trend',
            'semantic_version' => '1.0.0',
            'code_hash' => hash('sha256', 'c'),
            'config_hash' => hash('sha256', 'cfg'),
            'lifecycle_state' => StrategyLifecycleState::Approved,
            'configuration' => ['x' => 1],
            'immutable' => true,
        ]);
        $assignment = $svc->assignApprovedStrategy($user, $fleet, ['strategy_key' => 'ema_trend']);
        $this->assertSame('APPROVED', $assignment->lifecycle_gate);

        try {
            $svc->assignApprovedStrategy($user, $fleet, ['strategy_key' => 'not_governed']);
            $this->fail('non-approved must fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_phase9_account_portfolio_global_risk_locks(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $portfolio = $svc->createPortfolio($user, ['name' => 'Risk PF']);
        $svc->createRiskLock($user, [
            'scope' => 'ACCOUNT',
            'fleet_account_public_id' => $fleet->public_id,
            'lock_code' => 'ACCT_DD',
            'reason' => 'test',
        ]);
        $this->assertTrue($svc->hasBlockingRiskLock($user, $fleet));
        $svc->createRiskLock($user, [
            'scope' => 'PORTFOLIO',
            'trading_portfolio_public_id' => $portfolio->public_id,
            'lock_code' => 'PF_DD',
            'reason' => 'test',
        ]);
        $this->assertTrue($svc->hasBlockingRiskLock($user, null, $portfolio));
        $svc->createRiskLock($user, [
            'scope' => 'GLOBAL',
            'lock_code' => 'GLOBAL',
            'reason' => 'halt',
        ]);
        $this->assertTrue($svc->hasBlockingRiskLock($user));
    }

    public function test_execution_router_into_phase10_account_bound_idempotency_no_copy(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $svc->verifyAccountEnvironment($user, $fleet, true);

        $instrument = TradingInstrument::query()->where('symbol', 'EURUSD')->firstOrFail();
        $intent = $this->createApprovedDemoIntent($user, $fleet->brokerAccount, $instrument);

        $first = $svc->routeToPhase10($user, $fleet->fresh(), $intent, 'fleet-idem-1');
        $this->assertSame('ROUTED_PHASE10', $first['route']->route_decision);
        $this->assertFalse($first['route']->copy_trading);
        $this->assertFalse($first['route']->ai_routed);
        $this->assertTrue($first['phase10_handoff']['allowed']);
        $this->assertSame('ExecutionEngineService::submitDemo', $first['phase10_handoff']['submit_via']);

        $second = $svc->routeToPhase10($user, $fleet->fresh(), $intent, 'fleet-idem-1');
        $this->assertTrue($second['phase10_handoff']['replayed']);
        $this->assertSame($first['route']->id, $second['route']->id);

        try {
            $svc->routeToPhase10($user, $fleet, $intent, 'ai-route', true);
            $this->fail('AI route must fail');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, ExecutionRoute::query()->where('copy_trading', true)->count());
    }

    public function test_correct_account_phase11_management_gate(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $svc->verifyAccountEnvironment($user, $fleet, true);
        $ok = $svc->assertManagementAccountBound($user, $fleet->fresh(), '12345', '900001');
        $this->assertTrue($ok['allowed']);

        $bad = $svc->assertManagementAccountBound($user, $fleet->fresh(), '12345', 'other-login');
        $this->assertFalse($bad['allowed']);
        $this->assertTrue($fleet->fresh()->safe_mode);
    }

    public function test_per_account_reconciliation_foreign_and_restart(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $ok = $svc->reconcileAccount($user, $fleet, true, [
            ['login' => '900001', 'owned_by_nexa' => true],
        ]);
        $this->assertSame(0, $ok->foreign_positions);
        $this->assertTrue($ok->restart_recovery);
        $this->assertFalse($ok->safe_mode_triggered);

        $foreign = $svc->reconcileAccount($user, $fleet->fresh(), false, [
            ['login' => 'other', 'owned_by_nexa' => false],
        ]);
        $this->assertGreaterThan(0, $foreign->foreign_positions);
        $this->assertTrue($foreign->safe_mode_triggered);
        $this->assertTrue($fleet->fresh()->safe_mode);
    }

    public function test_fleet_health_automation_scope_emergency(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $snap = $svc->captureFleetHealth($user);
        $this->assertContains($snap->overall, ['HEALTHY', 'EMPTY', 'DEGRADED']);

        $scope = $svc->setAutomationScope($user, $fleet, ['mode' => 'DRY_RUN']);
        $this->assertSame('DRY_RUN', $scope->mode);

        try {
            $svc->setAutomationScope($user, $fleet, ['mode' => 'LIVE_AUTO']);
            $this->fail('LIVE_AUTO must fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $halt = $svc->emergency($user, ['scope' => 'ACCOUNT', 'action' => 'HALT', 'fleet_account_public_id' => $fleet->public_id, 'reason' => 'test']);
        $this->assertSame('ACTIVE', $halt->status);
        $this->assertTrue($fleet->fresh()->safe_mode);
    }

    public function test_valuation_and_portfolio_analytics(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $portfolio = $svc->createPortfolio($user, ['name' => 'FX', 'base_currency' => 'USD']);
        $svc->addMembership($user, $portfolio, $fleet);
        $norm = $svc->normalizeValuation($user, 'EUR', 'USD', 100);
        $this->assertSame('EUR', $norm['from']);
        $analytics = $svc->portfolioAnalytics($user, $portfolio);
        $this->assertArrayHasKey('total_equity_base', $analytics);
        $this->assertFalse($analytics['ai_mutable']);
    }

    public function test_trading_node_leases_split_brain(): void
    {
        $user = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($user);
        $svc = app(BrokerFleetService::class);
        $n1 = $svc->registerNode($user, ['node_id' => 'node-a']);
        $n2 = $svc->registerNode($user, ['node_id' => 'node-b']);
        $lease = $svc->acquireLease($user, $n1, $fleet, 120);
        $this->assertSame('HELD', $lease->status);
        try {
            $svc->acquireLease($user, $n2, $fleet, 120);
            $this->fail('split brain must block');
        } catch (ValidationException) {
            $this->assertTrue($fleet->fresh()->safe_mode);
        }
    }

    public function test_api_rbac_idor_and_refuse_probes(): void
    {
        $owner = $this->userWithRole('TRADER');
        $other = $this->userWithRole('TRADER');
        [, , $fleet] = $this->seedFleet($owner);

        $this->actingAs($owner)->getJson('/api/v1/fleet/dashboard')->assertOk()
            ->assertJsonPath('data.copy_trading', false)
            ->assertJsonPath('data.live_auto_exists', false);

        $this->actingAs($other)->postJson("/api/v1/fleet/accounts/{$fleet->public_id}/verify")
            ->assertNotFound();

        $this->actingAs($owner)->postJson('/api/v1/fleet/ai-route')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/fleet/live-auto')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/fleet/copy-trading')->assertForbidden();

        $viewer = $this->userWithRole('VIEWER');
        $this->actingAs($viewer)->postJson('/api/v1/fleet/providers', [
            'code' => 'X',
            'name' => 'X',
        ])->assertForbidden();

        $this->actingAs($owner)->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.broker_fleet.phase', 18)
            ->assertJsonPath('data.broker_fleet.copy_trading', false)
            ->assertJsonPath('data.broker_fleet.order_send_phase18', 0);
    }

    public function test_ai_cannot_register_provider(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/fleet/providers', [
            'code' => 'AI',
            'name' => 'AI',
            'actor_type' => 'AI',
        ])->assertForbidden();
    }

    /** @return array{0:\App\Models\BrokerProvider,1:\App\Models\BrokerConnection,2:FleetAccount} */
    private function seedFleet(User $user, string $login = '900001'): array
    {
        $this->seedInstruments();
        $profile = $user->riskProfiles()->create([
            'name' => 'Fleet Profile',
            'status' => 'ACTIVE',
            'is_default' => true,
            'max_risk_per_trade' => 1,
            'max_lot_size' => 1,
            'max_daily_loss' => 5,
            'max_weekly_loss' => 10,
            'max_drawdown' => 20,
            'max_open_positions' => 8,
            'max_open_risk' => 5,
            'max_correlated_exposure' => 4,
            'max_trades_per_day' => 20,
            'max_consecutive_losses' => 4,
            'min_margin_level' => 300,
            'max_spread' => 50,
            'max_slippage' => 1.5,
            'min_reward_risk' => 1.5,
            'require_stop_loss' => true,
            'sizing_enabled' => true,
        ]);
        $account = $user->brokerAccounts()->create([
            'risk_profile_id' => $profile->id,
            'name' => 'Fleet DEMO '.$login,
            'environment' => TradingEnvironment::Demo,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
            'broker_login' => $login,
            'broker_server' => 'Nexa-Demo',
            'account_reference' => 'fleet-'.$login.'-'.uniqid(),
            'metadata' => ['equity' => 10000],
        ]);
        $account->snapshots()->create([
            'balance' => 10000,
            'equity' => 10000,
            'margin' => 0,
            'free_margin' => 10000,
            'floating_pnl' => 0,
            'drawdown' => 0,
            'captured_at' => now()->subMinute(),
        ]);
        $svc = app(BrokerFleetService::class);
        $provider = $svc->registerProvider($user, [
            'code' => 'MT5_'.substr(md5($login.uniqid()), 0, 6),
            'name' => 'MT5 Demo',
            'platform' => 'MT5',
        ]);
        $conn = $svc->registerConnection($user, [
            'provider_public_id' => $provider->public_id,
            'name' => 'Mock '.$login,
            'endpoint_mode' => 'MOCK',
        ]);
        $fleet = $svc->registerFleetAccount($user, [
            'provider_public_id' => $provider->public_id,
            'broker_account_public_id' => $account->public_id,
            'connection_public_id' => $conn->public_id,
            'display_name' => 'Fleet '.$login,
            'login' => $login,
            'server' => 'Nexa-Demo',
            'environment' => 'DEMO',
            'currency' => 'USD',
        ]);

        return [$provider, $conn, $fleet];
    }

    private function seedSafety(bool $demoEnabled): void
    {
        foreach ([
            'emergency_stop' => false,
            'simulation_execution_enabled' => true,
            'allow_demo_execution' => $demoEnabled,
            'auto_demo_execution' => false,
            'allow_live_execution' => false,
            'trading_enabled' => false,
        ] as $key => $value) {
            ApplicationSetting::updateOrCreate(['key' => $key], ['group' => 'trading', 'value' => $value]);
        }
    }

    private function createApprovedDemoIntent(User $user, BrokerAccount $account, TradingInstrument $instrument, string $volume = '0.10'): TradeIntent
    {
        $created = $this->actingAs($user)->postJson('/api/v1/trade-intents', [
            'account_public_id' => $account->public_id,
            'instrument_public_id' => $instrument->public_id,
            'idempotency_key' => 'intent-'.uniqid('', true),
            'side' => OrderDirection::Buy->value,
            'order_type' => OrderType::Market->value,
            'requested_volume' => $volume,
            'stop_loss' => '1.09000',
            'take_profit' => '1.12000',
        ])->assertCreated()->json('data');

        $intent = TradeIntent::query()->where('public_id', $created['public_id'])->firstOrFail();
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_APPROVED');

        return $intent->fresh(['riskDecision', 'instrument', 'brokerAccount']);
    }
}
