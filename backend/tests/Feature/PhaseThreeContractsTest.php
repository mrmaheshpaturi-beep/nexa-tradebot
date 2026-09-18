<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProvider;
use App\Contracts\PositionReconciliationService;
use App\Enums\OrderDirection;
use App\Enums\Timeframe;
use App\Models\BrokerAccount;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\TradingInstrument;
use App\Services\FinancialCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseThreeContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_market_data_and_financial_calculations_are_deterministic(): void
    {
        $instrument = $this->instrument();
        $provider = app(MarketDataProvider::class);
        $calculator = app(FinancialCalculator::class);

        $first = $provider->getQuote('EURUSD');
        $second = $provider->getQuote('EURUSD');

        $this->assertSame('1.10000', $first['bid']);
        $this->assertSame($first['bid'], $second['bid']);
        $this->assertSame('MOCK', $first['source']);
        $this->assertSame('SIMULATION', $first['environment']);
        $candles = $provider->getCandles('EURUSD', Timeframe::M1, 3);
        $this->assertCount(3, $candles);
        $this->assertSame('M1', $candles[0]['timeframe']);
        $this->assertSame('MOCK', $candles[0]['source']);
        $this->assertSame('SIMULATION', $candles[0]['environment']);
        $this->assertArrayHasKey('tick_volume', $candles[0]);
        $this->assertSame('EURUSD', $provider->getSymbolSpecification('EURUSD')['symbol']);
        $this->assertTrue($calculator->isVolumeValid($instrument, 0.1));
        $this->assertFalse($calculator->isVolumeValid($instrument, 0.105));
        $this->assertEqualsWithDelta(100.0, $calculator->profit($instrument, OrderDirection::Buy, 0.1, 1.1, 1.11), 0.0001);
        $this->assertEqualsWithDelta(2.0, $calculator->rewardRisk(OrderDirection::Buy, 1.1, 1.095, 1.11), 0.0001);
    }

    public function test_instrument_api_exposes_the_exact_backend_mock_quote_and_price_semantics(): void
    {
        $viewer = $this->userWithRole('VIEWER');
        $instrument = $this->instrument();

        $this->actingAs($viewer)->getJson('/api/v1/instruments?symbol=eurusd')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.public_id', $instrument->public_id)
            ->assertJsonPath('data.data.0.digits', 5)
            ->assertJsonPath('data.data.0.tick_size', '0.0000100000')
            ->assertJsonPath('data.data.0.minimum_stop_distance', '0.0000000000')
            ->assertJsonPath('data.data.0.mock_quote.symbol', 'EURUSD')
            ->assertJsonPath('data.data.0.mock_quote.bid', '1.10000')
            ->assertJsonPath('data.data.0.mock_quote.ask', '1.10020')
            ->assertJsonPath('data.data.0.mock_quote.source', 'MOCK')
            ->assertJsonPath('data.data.0.mock_quote.environment', 'SIMULATION');
    }

    public function test_simulation_reconciliation_detects_and_accepts_deal_consistency(): void
    {
        $user = $this->userWithRole('TRADER');
        $instrument = $this->instrument();
        $account = $user->brokerAccounts()->create(['name' => 'Reconciliation', 'environment' => 'SIMULATION']);
        $order = $this->order($user->id, $account, $instrument);
        $position = Position::create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'opening_order_id' => $order->id,
            'trading_instrument_id' => $instrument->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'volume' => 0.1,
            'initial_volume' => 0.1,
            'current_volume' => 0.1,
            'open_price' => 1.1,
            'average_entry_price' => 1.1,
            'status' => 'OPEN',
            'environment' => 'SIMULATION',
        ]);
        Deal::create([
            'order_id' => $order->id,
            'broker_account_id' => $account->id,
            'position_id' => $position->id,
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'side' => 'BUY',
            'volume' => 0.1,
            'price' => 1.1,
            'type' => 'ENTRY',
            'origin' => 'SIMULATION',
            'environment' => 'SIMULATION',
            'dealt_at' => now(),
            'executed_at' => now(),
        ]);

        $service = app(PositionReconciliationService::class);
        $this->assertSame(['consistent' => true, 'issues' => []], $service->reconcile($position));

        $position->update(['volume' => 0.2, 'current_volume' => 0.2]);
        $this->assertFalse($service->reconcile($position->fresh())['consistent']);
    }

    public function test_phase_three_indexes_support_idempotent_and_lifecycle_queries(): void
    {
        $this->assertTrue(Schema::hasColumns('trade_intents', [
            'requested_volume', 'requested_entry', 'take_profit_2', 'time_in_force', 'comment', 'created_by',
        ]));
        $this->assertTrue(Schema::hasColumns('risk_decisions', [
            'decision', 'requested_risk', 'approved_risk', 'requested_volume', 'approved_volume', 'reason',
        ]));
        $this->assertTrue(Schema::hasColumns('execution_commands', [
            'symbol', 'side', 'order_type', 'volume', 'price', 'stop_loss', 'take_profit', 'expiration',
            'attempt_count', 'requested_at', 'acknowledged_at', 'completed_at', 'failed_at', 'error_code', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('orders', [
            'trade_intent_id', 'external_order_id', 'requested_volume', 'average_fill_price', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('deals', [
            'external_deal_id', 'side', 'swap', 'fee', 'executed_at', 'metadata', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('positions', [
            'signal_id', 'external_position_id', 'current_volume', 'average_entry_price', 'unrealized_pnl', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('position_events', ['previous_state', 'new_state', 'source']));
        $this->assertTrue(Schema::hasColumns('trading_terminals', [
            'platform', 'machine_identifier', 'environment', 'status', 'version',
            'last_heartbeat_at', 'last_connected_at', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('trading_instruments', [
            'display_name', 'base_currency', 'quote_currency', 'tick_size', 'tick_value',
            'minimum_volume', 'maximum_volume', 'step_volume', 'minimum_stop_distance',
        ]));
        $this->assertTrue(Schema::hasColumns('service_heartbeats', [
            'service', 'instance_id', 'status', 'last_seen_at', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('account_snapshots', ['open_positions']));
        $intentIndexes = collect(Schema::getIndexes('trade_intents'))->pluck('columns')->all();
        $commandIndexes = collect(Schema::getIndexes('execution_commands'))->pluck('columns')->all();
        $orderIndexes = collect(Schema::getIndexes('orders'))->pluck('columns')->all();

        $this->assertContains(['user_id', 'idempotency_key'], $intentIndexes);
        $this->assertContains(['user_id', 'idempotency_key'], $commandIndexes);
        $this->assertContains(['broker_account_id', 'environment', 'status', 'created_at'], $orderIndexes);
    }

    public function test_system_health_reports_exact_phase_three_safety_boundary(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.web_application.status', 'ONLINE')
            ->assertJsonPath('data.authentication.status', 'ONLINE')
            ->assertJsonPath('data.market_data.status', 'ENGINE_READY')
            ->assertJsonPath('data.market_data_engine.status', 'READY')
            ->assertJsonPath('data.market_data.read_only', true)
            ->assertJsonPath('data.trading_engine.mode', 'SIMULATION')
            ->assertJsonPath('data.terminal.status', 'OFFLINE')
            ->assertJsonPath('data.broker.status', 'DISCONNECTED')
            ->assertJsonPath('data.risk_execution.mode', 'SIMULATION_ONLY')
            ->assertJsonPath('data.execution.broker_transmission', false)
            ->assertJsonPath('data.allow_demo_execution', false)
            ->assertJsonPath('data.allow_live_execution', false);
        $this->assertFalse(method_exists(Signal::class, 'execute'));
    }

    private function instrument(): TradingInstrument
    {
        return TradingInstrument::create([
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
        ]);
    }

    private function order(int $userId, BrokerAccount $account, TradingInstrument $instrument): Order
    {
        return Order::create([
            'public_id' => '32000000-0000-4000-8000-000000000001',
            'command_id' => '32000000-0000-4000-8000-000000000002',
            'user_id' => $userId,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'idempotency_key' => 'reconciliation-order',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'type' => 'MARKET',
            'volume' => 0.1,
            'status' => 'FILLED',
            'environment' => 'SIMULATION',
        ]);
    }
}
