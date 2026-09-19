<?php

namespace Tests\Feature;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\TradingEnvironment;
use App\Execution\FakeDemoBridgeClient;
use App\Models\ApplicationSetting;
use App\Models\BrokerAccount;
use App\Models\ExecutionCommand;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\User;
use App\Services\ExecutionGate;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseTenExecutionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_auto_demo_off_and_live_locked(): void
    {
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_demo_execution']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['auto_demo_execution']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_live_execution']);
        $this->assertContains('allow_live_execution', SettingsService::LOCKED_FALSE);
        $this->assertContains('auto_demo_execution', SettingsService::LOCKED_FALSE);
        $this->assertNotContains('allow_demo_execution', SettingsService::LOCKED_FALSE);
    }

    public function test_live_and_unknown_hard_fail_at_gate(): void
    {
        $user = $this->userWithRole('TRADER');
        $live = $user->brokerAccounts()->create([
            'name' => 'Live Reject',
            'environment' => TradingEnvironment::Live,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
        ]);
        $gate = app(ExecutionGate::class);
        try {
            $gate->assertCanExecute(TradingEnvironment::Live, $live);
            $this->fail('LIVE must hard fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->seedSafety(true);
        $unknown = $user->brokerAccounts()->create([
            'name' => 'Unknown Demo',
            'environment' => TradingEnvironment::Demo,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
            'broker_login' => '900001',
            'broker_server' => 'Nexa-Demo',
            'verified_trade_mode' => 'UNKNOWN',
        ]);
        try {
            $gate->assertCanExecute(TradingEnvironment::Demo, $unknown);
            $this->fail('UNKNOWN must hard fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_demo_lifecycle_two_step_confirm_and_idempotent_submit(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account, $instrument] = $this->demoFixtures($user);
        $intent = $this->createApprovedDemoIntent($user, $account, $instrument);

        $step1 = $this->actingAs($user)->postJson("/api/v1/execution/intents/{$intent->public_id}/confirmations", [
            'idempotency_key' => 'cnf-1',
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($step1['challenge_token']);
        $this->assertFalse($step1['confirmation']['preview_payload']['auto_demo'] ?? true);

        $step2 = $this->actingAs($user)->postJson("/api/v1/execution/confirmations/{$step1['confirmation']['public_id']}/step2", [
            'challenge_token' => $step1['challenge_token'],
        ])->assertOk()->json('data');

        $submit = $this->actingAs($user)->postJson('/api/v1/execution/demo/submit', [
            'trade_intent_public_id' => $intent->public_id,
            'confirmation_public_id' => $step1['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'exe-1',
        ])->assertCreated()->json('data');

        $this->assertFalse($submit['replayed']);
        $this->assertSame('DEMO', $submit['environment']);
        $this->assertSame('COMPLETED', $submit['command']['status']);
        $this->assertNotNull($submit['result']);

        $replay = $this->actingAs($user)->postJson('/api/v1/execution/demo/submit', [
            'trade_intent_public_id' => $intent->public_id,
            'confirmation_public_id' => $step1['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'exe-1',
        ])->assertOk()->json('data');
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, ExecutionCommand::query()->where('idempotency_key', 'exe-1')->count());
    }

    public function test_live_trade_mode_from_bridge_hard_fails_verification(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account] = $this->demoFixtures($user);
        app(FakeDemoBridgeClient::class)->forceLiveTradeMode = true;

        $this->actingAs($user)->postJson("/api/v1/execution/accounts/{$account->public_id}/verify")
            ->assertStatus(422);
    }

    public function test_timeout_unknown_forbids_blind_retry_and_recovery(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account, $instrument] = $this->demoFixtures($user);
        $intent = $this->createApprovedDemoIntent($user, $account, $instrument);
        app(FakeDemoBridgeClient::class)->forceTimeout = true;

        $step1 = $this->actingAs($user)->postJson("/api/v1/execution/intents/{$intent->public_id}/confirmations", [
            'idempotency_key' => 'cnf-to',
        ])->assertCreated()->json('data');
        $step2 = $this->actingAs($user)->postJson("/api/v1/execution/confirmations/{$step1['confirmation']['public_id']}/step2", [
            'challenge_token' => $step1['challenge_token'],
        ])->assertOk()->json('data');

        $submit = $this->actingAs($user)->postJson('/api/v1/execution/demo/submit', [
            'trade_intent_public_id' => $intent->public_id,
            'confirmation_public_id' => $step1['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'exe-to',
        ])->assertCreated()->json('data');

        $this->assertSame('UNKNOWN', $submit['command']['submission_state']);
        $this->assertTrue($submit['command']['blind_retry_forbidden']);
        $this->assertSame('TIMEOUT_UNKNOWN', $submit['result']['outcome']);

        $this->actingAs($user)->postJson('/api/v1/execution/commands/'.$submit['command']['public_id'].'/recover')
            ->assertOk()
            ->assertJsonPath('data.blind_retry', false);
    }

    public function test_partial_fill_supported(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account, $instrument] = $this->demoFixtures($user);
        $intent = $this->createApprovedDemoIntent($user, $account, $instrument, '0.20');
        app(FakeDemoBridgeClient::class)->forcePartialFill = true;

        $step1 = $this->actingAs($user)->postJson("/api/v1/execution/intents/{$intent->public_id}/confirmations", [
            'idempotency_key' => 'cnf-pf',
        ])->assertCreated()->json('data');
        $step2 = $this->actingAs($user)->postJson("/api/v1/execution/confirmations/{$step1['confirmation']['public_id']}/step2", [
            'challenge_token' => $step1['challenge_token'],
        ])->assertOk()->json('data');
        $submit = $this->actingAs($user)->postJson('/api/v1/execution/demo/submit', [
            'trade_intent_public_id' => $intent->public_id,
            'confirmation_public_id' => $step1['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'exe-pf',
        ])->assertCreated()->json('data');

        $this->assertTrue($submit['result']['partial_fill']);
        $this->assertSame('PARTIALLY_FILLED', $submit['result']['outcome']);
    }

    public function test_health_documents_sole_order_send_path(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/execution/health')
            ->assertOk()
            ->assertJsonPath('data.live_execution', 'HARD_FAIL')
            ->assertJsonPath('data.auto_demo_execution', false)
            ->assertJsonPath('data.order_send_location', 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send');

        $root = dirname(__DIR__, 3);
        $sources = shell_exec('rg -n "authorized_order_send" '.$root.'/trading-engine/src/nexa_mt5/execution.py 2>/dev/null || true');
        $this->assertStringContainsString('authorized_order_send', (string) $sources);
        $appHits = shell_exec('rg -n "mt5\\.order_send|MetaTrader5\\.order_send" '.$root.'/backend/app 2>/dev/null || true');
        $this->assertSame('', trim((string) $appHits));
    }

    public function test_simulation_execute_rejects_demo_intent(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account, $instrument] = $this->demoFixtures($user);
        $intent = $this->createApprovedDemoIntent($user, $account, $instrument);
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'sim-wrong',
        ])->assertStatus(422);
    }

    public function test_system_status_exposes_phase_ten_and_defaults(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.execution_engine.phase', 10)
            ->assertJsonPath('data.allow_demo_execution', false)
            ->assertJsonPath('data.auto_demo_execution', false)
            ->assertJsonPath('data.allow_live_execution', false);
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

    /** @return array{0:BrokerAccount,1:TradingInstrument} */
    private function demoFixtures(User $user): array
    {
        $this->seedInstruments();
        $profile = $user->riskProfiles()->create([
            'name' => 'Demo Profile',
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
            'name' => 'Phase 10 DEMO',
            'environment' => TradingEnvironment::Demo,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
            'broker_login' => '900001',
            'broker_server' => 'Nexa-Demo',
            'verified_trade_mode' => 'DEMO',
            'demo_verified_at' => now(),
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
        $instrument = TradingInstrument::query()->where('symbol', 'EURUSD')->firstOrFail();

        return [$account, $instrument];
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
        $this->assertSame('DEMO', $intent->environment->value);

        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_APPROVED');

        return $intent->fresh(['riskDecision', 'instrument', 'brokerAccount']);
    }
}
