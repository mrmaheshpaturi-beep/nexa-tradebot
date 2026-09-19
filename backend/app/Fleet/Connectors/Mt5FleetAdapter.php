<?php

namespace App\Fleet\Connectors;

use App\Enums\AccountTradeMode;
use App\Enums\TradingEnvironment;
use App\Execution\DemoAccountVerifier;
use App\Fleet\Support\FleetSafety;
use App\Models\BrokerAccount;
use App\Models\FleetAccount;
use Illuminate\Validation\ValidationException;

/**
 * MT5 adapter abstraction for fleet connectivity.
 * Does NOT execute orders — Phase 10 remains sole execution authority.
 */
class Mt5FleetAdapter implements BrokerConnectorInterface
{
    public function __construct(private readonly DemoAccountVerifier $verifier) {}

    public function providerCode(): string
    {
        return 'MT5';
    }

    /**
     * Independently verify account environment. LIVE/UNKNOWN hard-blocked for broker-changing readiness.
     *
     * @return array{trade_mode:string,login:string,server:string,verified_at:string,environment:string,broker_changing_allowed:bool}
     */
    public function verifyEnvironment(FleetAccount $fleetAccount, bool $refresh = true): array
    {
        $account = $fleetAccount->brokerAccount;
        if ($account === null) {
            throw ValidationException::withMessages(['account' => 'Fleet account missing broker account binding.']);
        }

        if ($account->environment === TradingEnvironment::Live
            || $fleetAccount->environment === 'LIVE'
            || $fleetAccount->environment === 'REAL') {
            throw ValidationException::withMessages([
                'account' => 'LIVE accounts are hard-blocked for broker-changing fleet actions.',
            ]);
        }

        // SIMULATION path: synthetic verification without bridge writes
        if ($account->environment === TradingEnvironment::Simulation
            || $fleetAccount->environment === 'SIMULATION') {
            return [
                'trade_mode' => 'SIMULATION',
                'login' => (string) ($fleetAccount->login ?: $account->account_reference ?: 'sim'),
                'server' => (string) ($fleetAccount->server ?: 'SIM'),
                'verified_at' => now()->utc()->toIso8601String(),
                'environment' => 'SIMULATION',
                'broker_changing_allowed' => false,
                'adapter' => $this->providerCode(),
                'execution_authority' => FleetSafety::EXECUTION_AUTHORITY,
            ];
        }

        $verified = $this->verifier->verify($account, $refresh);
        $mode = AccountTradeMode::fromBridge($verified['trade_mode'] ?? 'UNKNOWN');
        if ($mode === AccountTradeMode::Live || $mode === AccountTradeMode::Unknown || $mode === AccountTradeMode::Contest) {
            throw ValidationException::withMessages([
                'account' => 'LIVE/UNKNOWN trade mode hard-blocked by MT5 fleet adapter.',
            ]);
        }

        $fleetAccount->forceFill([
            'login' => $verified['login'],
            'server' => $verified['server'],
            'verified_trade_mode' => AccountTradeMode::Demo->value,
            'environment_verified_at' => now(),
            'environment' => 'DEMO',
        ])->save();

        return [
            'trade_mode' => AccountTradeMode::Demo->value,
            'login' => $verified['login'],
            'server' => $verified['server'],
            'verified_at' => $verified['verified_at'],
            'environment' => 'DEMO',
            'broker_changing_allowed' => true,
            'adapter' => $this->providerCode(),
            'execution_authority' => FleetSafety::EXECUTION_AUTHORITY,
            'order_send' => false,
            'routes_into_phase_10' => true,
        ];
    }

    /** @return array{order_send:bool,routes_into_phase_10:bool,duplicate_execution_engine:bool} */
    public function executionBoundary(): array
    {
        return [
            'order_send' => false,
            'routes_into_phase_10' => true,
            'duplicate_execution_engine' => false,
            'order_send_location' => FleetSafety::ORDER_SEND_LOCATION,
        ];
    }
}
