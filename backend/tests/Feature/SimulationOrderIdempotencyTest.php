<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_returns_same_order_and_one_audit_record(): void
    {
        $user = $this->userWithRole('TRADER');
        ApplicationSetting::create(['key' => 'emergency_stop', 'value' => false]);
        ApplicationSetting::create(['key' => 'trading_enabled', 'value' => false]);
        ApplicationSetting::create(['key' => 'simulation_execution_enabled', 'value' => true]);
        $payload = [
            'command_id' => '20000000-0000-4000-8000-000000000001',
            'idempotency_key' => 'unique-request-1',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'volume' => 0.1,
            'risk_amount' => 25,
            'risk_percent' => 0.25,
            'stop_loss' => 1.08,
            'take_profit' => 1.12,
            'comment' => 'Simulation command only.',
        ];

        $first = $this->actingAs($user)->postJson('/api/v1/simulation/orders', $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/v1/simulation/orders', $payload)->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $second->assertJsonPath('data.idempotent_replay', true);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('positions', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_idempotency_keys_are_scoped_per_user(): void
    {
        $firstUser = $this->userWithRole('TRADER');
        $secondUser = $this->userWithRole('TRADER');
        ApplicationSetting::create(['key' => 'emergency_stop', 'value' => false]);
        ApplicationSetting::create(['key' => 'trading_enabled', 'value' => false]);
        ApplicationSetting::create(['key' => 'simulation_execution_enabled', 'value' => true]);
        $payload = [
            'command_id' => '20000000-0000-4000-8000-000000000002',
            'idempotency_key' => 'shared',
            'symbol' => 'USDJPY',
            'direction' => 'SELL',
            'volume' => 0.2,
        ];

        $this->actingAs($firstUser)->postJson('/api/v1/simulation/orders', $payload)->assertCreated();
        $payload['command_id'] = '20000000-0000-4000-8000-000000000003';
        $this->actingAs($secondUser)->postJson('/api/v1/simulation/orders', $payload)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
    }
}
