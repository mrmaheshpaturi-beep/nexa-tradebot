<?php

namespace Tests\Feature;

use Tests\TestCase;

class SimulationSafetyTest extends TestCase
{
    public function test_status_explicitly_disables_execution(): void
    {
        $this->getJson('/api/v1/simulation/status')
            ->assertOk()
            ->assertJsonPath('data.environment', 'SIMULATION')
            ->assertJsonPath('data.execution_available', false)
            ->assertJsonPath('data.broker_connected', false);
    }

    public function test_order_endpoint_only_creates_simulation_record(): void
    {
        $this->postJson('/api/v1/simulation/orders', [
            'symbol' => 'XAUUSD',
            'direction' => 'BUY',
            'volume' => 0.4,
        ])->assertCreated()
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.broker_transmitted', false);
    }

    public function test_order_validation_rejects_unsupported_inputs(): void
    {
        $this->postJson('/api/v1/simulation/orders', [
            'symbol' => 'UNKNOWN',
            'direction' => 'LIVE',
            'volume' => 100,
        ])->assertUnprocessable();
    }
}
