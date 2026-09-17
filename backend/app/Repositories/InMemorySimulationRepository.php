<?php

namespace App\Repositories;

use App\Contracts\SimulationRepository;
use Illuminate\Support\Str;

final class InMemorySimulationRepository implements SimulationRepository
{
    public function systemStatus(): array
    {
        return [
            'environment' => 'SIMULATION',
            'execution_available' => false,
            'broker_connected' => false,
            'market_data' => 'MOCK',
            'risk_engine' => 'SIMULATION',
            'version' => '1.0.0-phase1',
        ];
    }

    public function createOrder(array $order): array
    {
        return [
            'ticket' => 'SIM-'.Str::upper(Str::random(8)),
            'accepted' => true,
            'simulated' => true,
            'order' => $order,
            'broker_transmitted' => false,
        ];
    }
}
