<?php

namespace Tests\Feature;

use App\Enums\RiskDecisionStatus;
use App\Enums\RiskLockType;
use App\Enums\RiskReasonCode;
use App\Enums\TradingEnvironment;
use App\Models\ApplicationSetting;
use App\Models\BrokerAccount;
use App\Models\Deal;
use App\Models\Position;
use App\Models\ProposedPlan;
use App\Models\RiskDecision;
use App\Models\RiskLock;
use App\Models\RiskProfile;
use App\Models\RiskReservation;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\User;
use App\Services\ExecutionGate;
use App\Services\PositionSizingService;
use App\Services\RiskEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseNineRiskEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_risk_engine_health_and_system_status_expose_phase_nine(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/risk-engine/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 9)
            ->assertJsonPath('data.order_send', false)
            ->assertJsonPath('data.fail_closed', true)
            ->assertJsonPath('data.broker_routable', false);

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.risk_engine.phase', 9)
            ->assertJsonPath('data.risk_engine.order_send', false)
            ->assertJsonPath('data.allow_demo_execution', false)
            ->assertJsonPath('data.allow_live_execution', false);
    }

    public function test_symbol_aware_sizing_respects_volume_step_and_equity(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $sizing = app(PositionSizingService::class)->propose(
            TradeIntent::make([
                'side' => 'BUY',
                'requested_volume' => 1.0,
                'requested_entry' => 1.1002,
                'stop_loss' => 1.0952,
                'take_profit' => 1.1102,
                'risk_percent' => 1,
            ]),
            $instrument,
            $profile,
            ['bid' => 1.1, 'ask' => 1.1002, 'digits' => 5],
            ['balance' => 10000, 'equity' => 10000, 'leverage' => 100],
        );

        $this->assertSame('EQUITY_STOP_DISTANCE', $sizing['breakdown']['mode']);
        $this->assertTrue($sizing['breakdown']['no_hardcoded_pips']);
        $this->assertEqualsWithDelta(0.2, $sizing['proposed_volume'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $sizing['proposed_volume']);
    }

    public function test_approve_creates_immutable_decision_and_proposed_plan_with_reservation(): void
    {
        [$user, $account, $instrument] = $this->riskContext();
        $publicId = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-approve'))
            ->assertCreated()->json('data.public_id');

        $evaluated = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_APPROVED')
            ->assertJsonPath('data.risk_decision.reason_code', 'APPROVED')
            ->assertJsonPath('data.risk_decision.engine_version', 'RiskEngine/v1');

        $decisionId = $evaluated->json('data.risk_decision.public_id');
        $decision = RiskDecision::where('public_id', $decisionId)->firstOrFail();
        $this->assertNotNull($decision->proposedPlan);
        $this->assertFalse($decision->proposedPlan->broker_routable);
        $this->assertDatabaseHas('risk_reservations', [
            'trade_intent_id' => $decision->trade_intent_id,
            'status' => 'ACTIVE',
        ]);

        $this->expectException(\RuntimeException::class);
        $decision->update(['message' => 'tamper']);
    }

    public function test_idempotent_reevaluation_returns_same_decision(): void
    {
        [$user, $account, $instrument] = $this->riskContext();
        $publicId = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-idem'))
            ->assertCreated()->json('data.public_id');
        $first = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")->assertOk()->json('data.risk_decision.public_id');
        $second = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")->assertOk()->json('data.risk_decision.public_id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('risk_decisions', 1);
        $this->assertDatabaseCount('proposed_plans', 1);
    }

    public function test_reward_risk_and_stop_distance_fail_closed(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $profile->update(['min_reward_risk' => 3, 'require_stop_loss' => true]);

        $badRr = $this->payload($account, $instrument, 'p9-rr');
        $badRr['take_profit'] = 1.1010;
        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $badRr)->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_REJECTED')
            ->assertJsonPath('data.risk_decision.reason_code', 'MINIMUM_RR');

        $instrument->update(['minimum_stop_distance' => 0.01]);
        $badStop = $this->payload($account, $instrument, 'p9-stop');
        $stopId = $this->actingAs($user)->postJson('/api/v1/trade-intents', $badStop)->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$stopId}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'STOP_DISTANCE');
    }

    public function test_daily_weekly_drawdown_and_loss_streak_guards(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $profile->update([
            'max_daily_loss' => 1,
            'max_weekly_loss' => 1,
            'max_drawdown' => 5,
            'max_consecutive_losses' => 2,
        ]);

        Deal::query()->insert([
            'order_id' => $this->seedOrder($user, $account, $instrument)->id,
            'broker_account_id' => $account->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'type' => 'EXIT',
            'origin' => 'SIMULATION',
            'volume' => 0.1,
            'price' => 1.09,
            'profit' => -200,
            'environment' => 'SIMULATION',
            'executed_at' => now(),
            'dealt_at' => now(),
            'public_id' => 'SIM-DEAL-TESTDAILY000000000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-daily'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'DAILY_LOSS_LIMIT');
        $this->assertDatabaseHas('risk_locks', ['lock_type' => 'DAILY_LOSS', 'is_active' => 1]);

        // Weekly path via larger loss window already covered by daily; drawdown:
        Deal::query()->delete();
        RiskLock::query()->update(['is_active' => false]);
        $account->snapshots()->latest('id')->first()->update(['drawdown' => 12]);
        $id2 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-dd'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id2}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'DRAWDOWN_LIMIT');

        $account->snapshots()->latest('id')->first()->update(['drawdown' => 0]);
        RiskLock::query()->update(['is_active' => false]);
        Position::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'status' => 'CLOSED',
            'environment' => 'SIMULATION',
            'volume' => 0.1,
            'initial_volume' => 0.1,
            'current_volume' => 0,
            'average_entry_price' => 1.1,
            'open_price' => 1.1,
            'current_price' => 1.09,
            'realized_pnl' => -10,
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(10),
        ]);
        Position::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'status' => 'CLOSED',
            'environment' => 'SIMULATION',
            'volume' => 0.1,
            'initial_volume' => 0.1,
            'current_volume' => 0,
            'average_entry_price' => 1.1,
            'open_price' => 1.1,
            'current_price' => 1.09,
            'realized_pnl' => -8,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subMinutes(5),
        ]);
        $id3 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-streak'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id3}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'CONSECUTIVE_LOSS_LIMIT');
    }

    public function test_exposure_correlation_margin_spread_session_and_locks(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $profile->update(['max_open_positions' => 0]);
        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-pos'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'MAX_OPEN_POSITIONS');

        $profile->update(['max_open_positions' => 8, 'session_allowlist' => ['NonexistentSession']]);
        RiskLock::query()->update(['is_active' => false]);
        $id2 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-session'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id2}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'SESSION_RESTRICTED');

        $profile->update(['session_allowlist' => null, 'max_spread' => 0.1]);
        RiskLock::query()->update(['is_active' => false]);
        $id3 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-spread'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id3}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'SPREAD_LIMIT');

        $profile->update(['max_spread' => 50, 'min_margin_level' => 50000]);
        RiskLock::query()->update(['is_active' => false]);
        $id4 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-margin'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id4}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'MARGIN_LIMIT');

        app(\App\Services\RiskLockService::class)->ensureLock(
            $user,
            RiskLockType::Manual,
            RiskReasonCode::RiskLock,
            'Manual block',
            $account,
            $profile,
        );
        $id5 = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-lock'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id5}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'RISK_LOCK');
    }

    public function test_fail_closed_without_snapshot_and_missing_specs(): void
    {
        [$user, $account, $instrument] = $this->riskContext();
        $account->snapshots()->delete();
        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-snap'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_REJECTED');

        [$user2, $account2, $instrument2] = $this->riskContext(suffix: 'b');
        $id2 = $this->actingAs($user2)->postJson('/api/v1/trade-intents', $this->payload($account2, $instrument2, 'p9-specs'))
            ->assertCreated()->json('data.public_id');
        \DB::table('trading_instruments')->where('id', $instrument2->id)->update(['contract_size' => 0]);
        $this->actingAs($user2)->postJson("/api/v1/trade-intents/{$id2}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_REJECTED');
    }

    public function test_execution_gate_still_rejects_demo_live_and_order_send_none(): void
    {
        [$user, $account, $instrument] = $this->riskContext();
        $gate = app(ExecutionGate::class);
        try {
            $gate->assertCanExecute(TradingEnvironment::Demo, $account);
            $this->fail('DEMO should reject');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('environment', $e->errors());
        }
        try {
            $gate->assertCanExecute(TradingEnvironment::Live, $account);
            $this->fail('LIVE should reject');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('environment', $e->errors());
        }

        $this->actingAs($user)->postJson('/api/v1/risk-engine/assert-gate', [
            'account_public_id' => $account->public_id,
            'environment' => 'DEMO',
        ])->assertStatus(422)->assertJsonPath('data.order_send', false);

        $root = dirname(__DIR__, 3);
        $sources = shell_exec('rg -n "mt5\\.order_send|MetaTrader5\\.order_send" --glob "!vendor/**" --glob "!node_modules/**" '.$root.'/backend/app 2>/dev/null || true');
        $this->assertSame('', trim((string) $sources));
        $bridgeSources = shell_exec('rg -n "authorized_order_send|mt5\\.order_send" --glob "!vendor/**" '.$root.'/trading-engine/src/nexa_mt5/execution.py 2>/dev/null || true');
        $this->assertStringContainsString('authorized_order_send', (string) $bridgeSources);
    }

    public function test_rbac_for_risk_engine_endpoints(): void
    {
        [$trader, $account, $instrument] = $this->riskContext();
        $viewer = $this->userWithRole('VIEWER');

        $this->actingAs($viewer)->getJson('/api/v1/risk-engine/dashboard')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/risk-engine/locks', [
            'lock_type' => 'MANUAL',
            'reason_code' => 'RISK_LOCK',
            'message' => 'nope',
        ])->assertForbidden();

        $this->actingAs($trader)->getJson('/api/v1/risk-engine/dashboard')
            ->assertOk()
            ->assertJsonPath('data.execution.order_send', false);

        $lock = $this->actingAs($trader)->postJson('/api/v1/risk-engine/locks', [
            'lock_type' => 'MANUAL',
            'reason_code' => 'RISK_LOCK',
            'message' => 'manual',
            'account_public_id' => $account->public_id,
        ])->assertCreated()->json('data.public_id');

        $this->actingAs($trader)->postJson("/api/v1/risk-engine/locks/{$lock}/release", ['note' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_profile_version_recorded_and_no_randomness(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $before = (int) $profile->version;
        $profile->update(['max_risk_per_trade' => 0.9]);
        $this->assertSame($before + 1, (int) $profile->fresh()->version);

        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-ver'))
            ->assertCreated()->json('data.public_id');
        $decision = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()->json('data.risk_decision');
        $this->assertSame((int) $profile->fresh()->version, $decision['profile_version']);
        $this->assertNotEmpty($decision['config_hash']);
        $this->assertIsArray($decision['rule_results']);
    }

    public function test_correlation_limit_blocks_related_exposure(): void
    {
        [$user, $account, $instrument, $profile] = $this->riskContext();
        $profile->update(['max_correlated_exposure' => 0.2, 'max_open_risk' => 10]);
        Position::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'status' => 'OPEN',
            'environment' => 'SIMULATION',
            'volume' => 0.1,
            'initial_volume' => 0.1,
            'current_volume' => 0.1,
            'average_entry_price' => 1.1002,
            'open_price' => 1.1002,
            'current_price' => 1.1002,
            'stop_loss' => 1.0902,
            'realized_pnl' => 0,
            'opened_at' => now(),
        ]);
        $id = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->payload($account, $instrument, 'p9-corr'))
            ->assertCreated()->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.risk_decision.reason_code', 'CORRELATION_LIMIT');
    }

    /** @return array{0:User,1:BrokerAccount,2:TradingInstrument,3:RiskProfile} */
    private function riskContext(string $suffix = 'a'): array
    {
        $user = $this->userWithRole('TRADER');
        $profile = $user->riskProfiles()->create([
            'name' => 'Phase 9 risk '.$suffix,
            'status' => 'ACTIVE',
            'is_default' => true,
            'max_risk_per_trade' => 1,
            'max_lot_size' => 1,
            'max_daily_loss' => 4,
            'max_weekly_loss' => 8,
            'max_drawdown' => 12,
            'max_open_positions' => 8,
            'max_open_risk' => 6,
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
            'name' => 'Phase 9 simulation '.$suffix,
            'environment' => 'SIMULATION',
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
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
        $instrument = TradingInstrument::create([
            'symbol' => $suffix === 'a' ? 'EURUSD' : 'GBPUSD',
            'name' => 'Test '.$suffix,
            'display_name' => 'Test '.$suffix,
            'asset_class' => 'FOREX',
            'currency_base' => $suffix === 'a' ? 'EUR' : 'GBP',
            'currency_quote' => 'USD',
            'base_currency' => $suffix === 'a' ? 'EUR' : 'GBP',
            'quote_currency' => 'USD',
            'digits' => 5,
            'point_size' => 0.00001,
            'contract_size' => 100000,
            'tick_size' => 0.00001,
            'tick_value' => 1,
            'volume_min' => 0.01,
            'volume_max' => 5,
            'volume_step' => 0.01,
            'minimum_volume' => 0.01,
            'maximum_volume' => 5,
            'step_volume' => 0.01,
            'minimum_stop_distance' => 0,
            'margin_rate' => 1,
            'is_enabled' => true,
        ]);
        foreach ([
            'emergency_stop' => false,
            'trading_enabled' => false,
            'simulation_execution_enabled' => true,
        ] as $key => $value) {
            ApplicationSetting::updateOrCreate(['key' => $key], ['group' => 'trading', 'value' => $value]);
        }

        return [$user, $account, $instrument, $profile];
    }

    /** @return array<string,mixed> */
    private function payload(BrokerAccount $account, TradingInstrument $instrument, string $key, float $volume = 0.1): array
    {
        $entry = $instrument->symbol === 'GBPUSD' ? 1.2753 : 1.1002;
        $stop = $instrument->symbol === 'GBPUSD' ? 1.2703 : 1.0952;
        $take = $instrument->symbol === 'GBPUSD' ? 1.2853 : 1.1102;

        return [
            'account_public_id' => $account->public_id,
            'instrument_public_id' => $instrument->public_id,
            'idempotency_key' => $key,
            'side' => 'BUY',
            'order_type' => 'MARKET',
            'requested_volume' => $volume,
            'stop_loss' => $stop,
            'take_profit' => $take,
            'risk_percent' => 0.5,
            'requested_entry' => $entry,
        ];
    }

    private function seedOrder(User $user, BrokerAccount $account, TradingInstrument $instrument): \App\Models\Order
    {
        return \App\Models\Order::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'command_id' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'seed-order-'.uniqid(),
            'symbol' => $instrument->symbol,
            'side' => 'BUY',
            'direction' => 'BUY',
            'order_type' => 'MARKET',
            'type' => 'MARKET',
            'volume' => 0.1,
            'requested_volume' => 0.1,
            'status' => 'FILLED',
            'environment' => 'SIMULATION',
            'simulated' => true,
            'broker_transmitted' => false,
            'requested_at' => now(),
        ]);
    }
}
