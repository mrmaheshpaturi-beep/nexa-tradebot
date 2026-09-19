<?php

namespace App\Fleet\Connectors;

use App\Models\FleetAccount;

interface BrokerConnectorInterface
{
    public function providerCode(): string;

    /** @return array<string, mixed> */
    public function verifyEnvironment(FleetAccount $fleetAccount, bool $refresh = true): array;

    /** @return array<string, mixed> */
    public function executionBoundary(): array;
}
