<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_explicitly_disables_execution(): void
    {
        $this->getJson('/api/v1/simulation/status')
            ->assertOk()
            ->assertJsonPath('data.environment', 'SIMULATION')
            ->assertJsonPath('data.execution.available', false)
            ->assertJsonPath('data.execution.broker_transmission', false)
            ->assertJsonPath('data.broker.connected', false)
            ->assertJsonPath('data.market_data.source', 'MOCK MARKET DATA');
    }

    public function test_order_endpoint_only_creates_simulation_record(): void
    {
        $user = $this->userWithRole('TRADER');
        ApplicationSetting::create(['key' => 'emergency_stop', 'value' => false]);
        ApplicationSetting::create(['key' => 'trading_enabled', 'value' => true]);

        $this->actingAs($user)->postJson('/api/v1/simulation/orders', [
            'command_id' => '10000000-0000-4000-8000-000000000001',
            'idempotency_key' => 'phase-one-compatibility',
            'symbol' => 'XAUUSD',
            'direction' => 'BUY',
            'volume' => 0.4,
        ])->assertCreated()
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.broker_transmitted', false);
    }

    public function test_order_validation_rejects_unsupported_inputs(): void
    {
        $user = $this->userWithRole('TRADER');

        $this->actingAs($user)->postJson('/api/v1/simulation/orders', [
            'command_id' => '10000000-0000-4000-8000-000000000002',
            'idempotency_key' => 'invalid',
            'symbol' => 'UNKNOWN',
            'direction' => 'LIVE',
            'volume' => 100,
        ])->assertUnprocessable();
    }

    public function test_emergency_stop_blocks_simulation_orders_by_default(): void
    {
        $user = $this->userWithRole('TRADER');

        $this->actingAs($user)->postJson('/api/v1/simulation/orders', [
            'command_id' => '10000000-0000-4000-8000-000000000003',
            'idempotency_key' => 'stopped',
            'symbol' => 'EURUSD',
            'direction' => 'SELL',
            'volume' => 0.1,
        ])->assertUnprocessable()->assertJsonValidationErrors('trading');
    }
}
