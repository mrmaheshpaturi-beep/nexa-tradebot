<?php

namespace Tests\Feature;

use App\Contracts\ExecutionAdapter;
use App\Enums\ExecutionCommandStatus;
use App\Enums\OrderStatus;
use App\Enums\PositionStatus;
use App\Enums\TradingEnvironment;
use App\Models\ApplicationSetting;
use App\Models\BrokerAccount;
use App\Models\Deal;
use App\Models\ExecutionCommand;
use App\Models\Order;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseThreeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_lifecycle_works_with_trading_disabled_and_only_simulation_execution_enabled(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $this->assertDatabaseHas('application_settings', ['key' => 'trading_enabled', 'value' => 'false']);
        $this->assertDatabaseHas('application_settings', ['key' => 'simulation_execution_enabled', 'value' => 'true']);
        $this->assertDatabaseHas('application_settings', ['key' => 'emergency_stop', 'value' => 'false']);
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.trading_enabled', false)
            ->assertJsonPath('data.simulation_execution_enabled', true)
            ->assertJsonPath('data.risk_execution.status', 'READY')
            ->assertJsonPath('data.execution.available', true);

        $created = $this->actingAs($user)->postJson('/api/v1/trade-intents', $this->intentPayload($account, $instrument, 'market-intent'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_RISK')
            ->assertJsonPath('data.requested_volume', '0.1000')
            ->assertJsonPath('data.created_by', $user->id);
        $publicId = $created->json('data.public_id');
        $this->assertDatabaseCount('risk_decisions', 0);
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_APPROVED')
            ->assertJsonPath('data.risk_decision.decision', 'APPROVED')
            ->assertJsonPath('data.risk_decision.reason_code', 'APPROVED')
            ->assertJsonPath('data.risk_decision.approved_volume', '0.1000');
        $executed = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/execute", [
            'idempotency_key' => 'execute-market-intent',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.order.status', 'FILLED')
            ->assertJsonPath('data.order.position.status', 'OPEN');

        $this->assertNotNull($executed->json('data.public_id'));
        $this->assertStringStartsWith('SIM-INT-', $publicId);
        $this->assertStringStartsWith('SIM-CMD-', $executed->json('data.public_id'));
        $this->assertStringStartsWith('SIM-ORD-', $executed->json('data.order.public_id'));
        $this->assertStringStartsWith('SIM-POS-', $executed->json('data.order.position.public_id'));
        $this->assertStringStartsWith('SIM-DEAL-', Deal::firstOrFail()->public_id);
        $this->assertNull($executed->json('data.order.external_order_id'));
        $this->assertSame('PLACE_ORDER', $executed->json('data.type'));
        $this->assertSame('EURUSD', $executed->json('data.symbol'));
        $this->assertNotNull($executed->json('data.requested_at'));
        $this->assertNotNull($executed->json('data.acknowledged_at'));
        $this->assertNotNull($executed->json('data.completed_at'));
        $this->assertDatabaseCount('execution_commands', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('deals', 1);
        $this->assertDatabaseCount('positions', 1);
        $this->assertDatabaseCount('position_events', 1);
        $this->assertDatabaseCount('account_snapshots', 2);
    }

    public function test_execution_idempotency_creates_one_command_order_and_position(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'idempotent-intent');
        $url = "/api/v1/trade-intents/{$intent->public_id}/execute";
        $payload = ['idempotency_key' => 'one-logical-command'];

        $first = $this->actingAs($user)->postJson($url, $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertDatabaseCount('execution_commands', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('positions', 1);
    }

    public function test_risk_rejection_creates_no_downstream_entities(): void
    {
        [$user, $account, $instrument] = $this->tradingContext(enableExecution: false);
        $created = $this->actingAs($user)->postJson(
            '/api/v1/trade-intents',
            $this->intentPayload($account, $instrument, 'rejected-intent'),
        )->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$created->json('data.public_id')}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.status', 'RISK_REJECTED')
            ->assertJsonPath('data.risk_decision.reason_code', 'EMERGENCY_STOP');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$created->json('data.public_id')}/execute", [
            'idempotency_key' => 'rejected-command',
        ])->assertUnprocessable()->assertJsonValidationErrors('intent');

        $this->assertDatabaseCount('risk_decisions', 1);
        $this->assertDatabaseCount('execution_commands', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_demo_and_live_environments_are_never_executable(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'environment-intent');
        $intent->update(['environment' => TradingEnvironment::Demo]);

        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'demo-command',
        ])->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->assertDatabaseCount('execution_commands', 0);

        $intent->update(['environment' => TradingEnvironment::Live]);
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'live-command',
        ])->assertUnprocessable()->assertJsonValidationErrors('environment');
    }

    public function test_pending_order_stops_at_accepted_and_can_be_cancelled(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $payload = $this->intentPayload($account, $instrument, 'pending-intent');
        $payload['order_type'] = 'BUY_LIMIT';
        $payload['requested_entry'] = 1.09;
        $payload['stop_loss'] = 1.08;
        $created = $this->actingAs($user)->postJson('/api/v1/trade-intents', $payload)->assertCreated();
        $publicId = $created->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")->assertOk();
        $executed = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/execute", [
            'idempotency_key' => 'execute-pending',
        ])->assertCreated()->assertJsonPath('data.order.status', 'ACCEPTED');
        $orderPublicId = $executed->json('data.order.public_id');
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('positions', 0);

        $this->actingAs($user)->postJson("/api/v1/orders/{$orderPublicId}/cancel", [
            'idempotency_key' => 'cancel-pending',
        ])->assertCreated()->assertJsonPath('data.type', 'CANCEL_ORDER');
        $this->assertDatabaseHas('orders', ['public_id' => $orderPublicId, 'status' => 'CANCELLED']);
    }

    public function test_typed_pending_order_must_match_its_side(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $payload = $this->intentPayload($account, $instrument, 'mismatched-pending-intent');
        $payload['side'] = 'SELL';
        $payload['order_type'] = 'BUY_LIMIT';
        $payload['requested_entry'] = 1.09;

        $this->actingAs($user)->postJson('/api/v1/trade-intents', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_type');
        $this->assertDatabaseCount('trade_intents', 0);
    }

    public function test_partial_close_protection_change_and_full_close_update_account_consistently(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'managed-position', volume: 0.2);
        $executed = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'open-managed-position',
        ])->assertCreated();
        $positionPublicId = $executed->json('data.order.position.public_id');

        $this->actingAs($user)->postJson("/api/v1/positions/{$positionPublicId}/partial-close", [
            'idempotency_key' => 'partial-managed-position',
            'volume' => 0.1,
        ])->assertCreated()->assertJsonPath('data.type', 'PARTIAL_CLOSE');
        $this->assertDatabaseHas('positions', [
            'public_id' => $positionPublicId,
            'status' => 'PARTIALLY_CLOSED',
            'volume' => 0.1,
        ]);

        $this->actingAs($user)->putJson("/api/v1/positions/{$positionPublicId}/stop-loss", [
            'idempotency_key' => 'stop-loss-managed-position',
            'stop_loss' => 1.096,
        ])->assertCreated()->assertJsonPath('data.type', 'MODIFY_POSITION_SL');
        $this->actingAs($user)->putJson("/api/v1/positions/{$positionPublicId}/take-profit", [
            'idempotency_key' => 'take-profit-managed-position',
            'take_profit' => 1.12,
        ])->assertCreated()->assertJsonPath('data.type', 'MODIFY_POSITION_TP');

        $this->actingAs($user)->postJson("/api/v1/positions/{$positionPublicId}/close", [
            'idempotency_key' => 'close-managed-position',
        ])->assertCreated()->assertJsonPath('data.type', 'CLOSE_POSITION');
        $position = Position::where('public_id', $positionPublicId)->firstOrFail();
        $this->assertSame('CLOSED', $position->status->value);
        $this->assertEqualsWithDelta(-4.0, (float) $position->realized_pnl, 0.0001);
        $this->assertEqualsWithDelta(9996.0, (float) $account->snapshots()->latest('captured_at')->value('balance'), 0.0001);
        $this->assertSame(0, $account->snapshots()->latest('captured_at')->value('open_positions'));
        $this->assertDatabaseCount('deals', 3);
        $this->assertDatabaseCount('position_events', 5);
        $this->assertDatabaseHas('position_events', ['type' => 'STOP_LOSS_MODIFIED', 'source' => 'SIMULATION']);
        $this->assertDatabaseHas('position_events', ['type' => 'TAKE_PROFIT_MODIFIED', 'source' => 'SIMULATION']);
    }

    public function test_partial_close_rejects_a_non_step_volume(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'step-position', volume: 0.2);
        $positionPublicId = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'open-step-position',
        ])->json('data.order.position.public_id');

        $this->actingAs($user)->postJson("/api/v1/positions/{$positionPublicId}/partial-close", [
            'idempotency_key' => 'bad-step',
            'volume' => 0.015,
        ])->assertUnprocessable()->assertJsonValidationErrors('volume');
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_signal_is_consumed_once_when_converted_to_an_intent(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $signal = Signal::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'status' => 'GENERATED',
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
            'generated_at' => now(),
            'expires_at' => now()->addHour(),
            'stop_loss' => 1.0952,
            'entry_reference' => 1.1002,
            'take_profit_1_reference' => 1.1102,
        ]);
        $payload = [
            'account_public_id' => $account->public_id,
            'idempotency_key' => 'signal-intent',
            'requested_volume' => 0.1,
        ];

        $first = $this->actingAs($user)->postJson("/api/v1/signals/{$signal->public_id}/trade-intent", $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson("/api/v1/signals/{$signal->public_id}/trade-intent", $payload)->assertUnprocessable();

        $this->assertNotNull($first->json('data.public_id'));
        $second->assertJsonValidationErrors('signal');
        $this->assertDatabaseHas('signals', ['id' => $signal->id, 'status' => 'CONSUMED']);
        $this->assertDatabaseCount('trade_intents', 1);
    }

    public function test_neutral_signal_cannot_create_a_trade_intent(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $signal = Signal::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'NEUTRAL',
            'status' => 'VALID',
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
            'generated_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->postJson("/api/v1/signals/{$signal->public_id}/trade-intent", [
            'account_public_id' => $account->public_id,
            'idempotency_key' => 'neutral-signal-intent',
            'requested_volume' => 0.1,
        ])->assertUnprocessable()->assertJsonValidationErrors('signal');
        $this->assertDatabaseCount('trade_intents', 0);
    }

    public function test_controlled_adapter_failure_is_persisted_without_order_or_position(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'failed-intent');
        $this->mock(ExecutionAdapter::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new DomainException('Deterministic adapter failure'));

        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'failed-command',
        ])->assertUnprocessable()->assertJsonValidationErrors('execution');

        $this->assertDatabaseHas('execution_commands', [
            'idempotency_key' => 'failed-command',
            'status' => 'FAILED',
            'failure_code' => 'SIMULATION_EXECUTION_FAILED',
        ]);
        $this->assertDatabaseHas('system_events', ['category' => 'SIMULATION_EXECUTION']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_controlled_position_failure_keeps_position_open_and_persists_failed_command(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $intent = $this->approvedIntent($user, $account, $instrument, 'position-failure-intent');
        $positionPublicId = $this->actingAs($user)->postJson("/api/v1/trade-intents/{$intent->public_id}/execute", [
            'idempotency_key' => 'open-before-position-failure',
        ])->assertCreated()->json('data.order.position.public_id');
        $this->mock(ExecutionAdapter::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new DomainException('Deterministic close failure'));

        $this->actingAs($user)->postJson("/api/v1/positions/{$positionPublicId}/close", [
            'idempotency_key' => 'failed-close-command',
        ])->assertUnprocessable()->assertJsonValidationErrors('execution');

        $this->assertDatabaseHas('execution_commands', [
            'idempotency_key' => 'failed-close-command',
            'status' => 'FAILED',
        ]);
        $this->assertDatabaseHas('positions', ['public_id' => $positionPublicId, 'status' => 'OPEN']);
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_phase_three_rbac_public_ids_schema_and_no_broker_adapter_contract(): void
    {
        [$trader, $account, $instrument] = $this->tradingContext();
        $viewer = $this->userWithRole('VIEWER');
        $analyst = $this->userWithRole('ANALYST');

        $this->actingAs($viewer)->getJson('/api/v1/instruments')->assertOk();
        $this->actingAs($viewer)->getJson('/api/v1/signals')->assertForbidden();
        $this->actingAs($analyst)->getJson('/api/v1/signals')->assertOk();
        $this->actingAs($analyst)->postJson('/api/v1/trade-intents', [])->assertForbidden();
        $this->actingAs($trader)->postJson('/api/v1/trade-intents', [])->assertUnprocessable();

        $this->assertNotNull($account->public_id);
        $this->assertNotNull($instrument->public_id);
        $this->assertTrue(Schema::hasColumns('execution_commands', ['public_id', 'environment', 'failure_code']));
        $this->assertTrue(Schema::hasColumns('positions', ['public_id', 'realized_pnl', 'margin_used']));
        $this->assertFalse(class_exists('App\\Services\\Mt5ExecutionAdapter'));
        $this->assertFalse(class_exists('App\\Services\\BrokerExecutionAdapter'));
    }

    public function test_transition_guards_prevent_order_status_reversal(): void
    {
        [$user, $account] = $this->tradingContext();
        $order = Order::create([
            'public_id' => '31000000-0000-4000-8000-000000000001',
            'command_id' => '31000000-0000-4000-8000-000000000002',
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'idempotency_key' => 'guarded-order',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'type' => 'MARKET',
            'volume' => 0.1,
            'status' => 'FILLED',
            'environment' => 'SIMULATION',
        ]);

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::Submitted);
    }

    public function test_cancelled_order_cannot_transition_to_filled(): void
    {
        [$user, $account] = $this->tradingContext();
        $order = Order::create([
            'command_id' => '33000000-0000-4000-8000-000000000002',
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'idempotency_key' => 'cancelled-order-guard',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'type' => 'BUY_LIMIT',
            'order_type' => 'BUY_LIMIT',
            'volume' => 0.1,
            'requested_volume' => 0.1,
            'status' => 'CANCELLED',
            'environment' => 'SIMULATION',
        ]);

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::Filled);
    }

    public function test_closed_position_cannot_transition_to_open(): void
    {
        [$user, $account, $instrument] = $this->tradingContext();
        $order = Order::create([
            'command_id' => '34000000-0000-4000-8000-000000000002',
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'idempotency_key' => 'closed-position-order',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'type' => 'MARKET',
            'order_type' => 'MARKET',
            'volume' => 0.1,
            'requested_volume' => 0.1,
            'status' => 'FILLED',
            'environment' => 'SIMULATION',
        ]);
        $position = Position::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'opening_order_id' => $order->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'volume' => 0,
            'initial_volume' => 0.1,
            'current_volume' => 0,
            'open_price' => 1.1,
            'average_entry_price' => 1.1,
            'status' => 'CLOSED',
            'environment' => 'SIMULATION',
        ]);

        $this->expectException(DomainException::class);
        $position->transitionTo(PositionStatus::Open);
    }

    public function test_completed_command_cannot_return_to_queued(): void
    {
        [$user, $account] = $this->tradingContext();
        $command = ExecutionCommand::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'idempotency_key' => 'completed-command-guard',
            'type' => 'PLACE_ORDER',
            'status' => 'COMPLETED',
            'environment' => 'SIMULATION',
            'symbol' => 'EURUSD',
            'side' => 'BUY',
            'order_type' => 'MARKET',
            'volume' => 0.1,
            'requested_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $command->transitionTo(ExecutionCommandStatus::Queued);
    }

    /** @return array{User,BrokerAccount,TradingInstrument,RiskProfile} */
    private function tradingContext(bool $enableExecution = true): array
    {
        $user = $this->userWithRole('TRADER');
        $profile = $user->riskProfiles()->create([
            'name' => 'Phase 3 test risk',
            'status' => 'ACTIVE',
            'is_default' => true,
            'max_risk_per_trade' => 1,
            'max_lot_size' => 1,
            'max_open_positions' => 8,
            'min_reward_risk' => 1.5,
        ]);
        $account = $user->brokerAccounts()->create([
            'risk_profile_id' => $profile->id,
            'name' => 'Phase 3 simulation',
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
            'symbol' => 'EURUSD',
            'name' => 'Euro / US Dollar',
            'display_name' => 'Euro / US Dollar',
            'asset_class' => 'FOREX',
            'currency_base' => 'EUR',
            'currency_quote' => 'USD',
            'base_currency' => 'EUR',
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
            'margin_rate' => 1,
            'is_enabled' => true,
        ]);
        foreach ([
            'emergency_stop' => ! $enableExecution,
            'trading_enabled' => false,
            'simulation_execution_enabled' => $enableExecution,
        ] as $key => $value) {
            ApplicationSetting::create(['key' => $key, 'group' => 'trading', 'value' => $value]);
        }

        return [$user, $account, $instrument, $profile];
    }

    private function approvedIntent(
        User $user,
        BrokerAccount $account,
        TradingInstrument $instrument,
        string $key,
        float $volume = 0.1,
    ): TradeIntent {
        $response = $this->actingAs($user)->postJson(
            '/api/v1/trade-intents',
            $this->intentPayload($account, $instrument, $key, $volume),
        )->assertCreated();
        $publicId = $response->json('data.public_id');
        $this->actingAs($user)->postJson("/api/v1/trade-intents/{$publicId}/evaluate")
            ->assertOk()->assertJsonPath('data.status', 'RISK_APPROVED');

        return TradeIntent::where('public_id', $publicId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function intentPayload(BrokerAccount $account, TradingInstrument $instrument, string $key, float $volume = 0.1): array
    {
        return [
            'account_public_id' => $account->public_id,
            'instrument_public_id' => $instrument->public_id,
            'idempotency_key' => $key,
            'side' => 'BUY',
            'order_type' => 'MARKET',
            'requested_volume' => $volume,
            'stop_loss' => 1.0952,
            'take_profit' => 1.1102,
            'risk_percent' => 0.5,
        ];
    }
}
