<?php

namespace App\Fleet;

use App\Enums\StrategyLifecycleState;
use App\Enums\TradingEnvironment;
use App\Fleet\Connectors\Mt5FleetAdapter;
use App\Fleet\Support\FleetSafety;
use App\Models\AccountFingerprint;
use App\Models\AllocationPlan;
use App\Models\AutomationAccountScope;
use App\Models\BrokerAccount;
use App\Models\BrokerCapability;
use App\Models\BrokerConnection;
use App\Models\BrokerInstrument;
use App\Models\BrokerProvider;
use App\Models\CanonicalInstrument;
use App\Models\ExecutionRoute;
use App\Models\FleetAccount;
use App\Models\FleetAuditEvent;
use App\Models\FleetEmergencyControl;
use App\Models\FleetHealthSnapshot;
use App\Models\FleetReconciliationRun;
use App\Models\FleetRiskLock;
use App\Models\FleetTerminal;
use App\Models\FxValuationRate;
use App\Models\GovernedStrategyVersion;
use App\Models\InstrumentMapping;
use App\Models\PortfolioMembership;
use App\Models\StrategyAssignment;
use App\Models\TradeIntent;
use App\Models\TradingNode;
use App\Models\TradingNodeLease;
use App\Models\TradingPortfolio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 18 Broker Fleet orchestrator.
 * Extends stable architecture; does not duplicate ExecutionEngine.
 */
class BrokerFleetService
{
    public function __construct(private readonly Mt5FleetAdapter $mt5) {}

    /** @return array<string, mixed> */
    public function healthPayload(): array
    {
        return array_merge(FleetSafety::matrix(), [
            'status' => 'READY',
            'connector' => $this->mt5->executionBoundary(),
            'providers_supported' => ['MT5', 'SIMULATION'],
            'ui' => [
                'command_center' => '/#/portfolio-command-center',
                'connections' => '/#/broker-connections',
                'account_wizard' => '/#/account-wizard',
            ],
            'windows_mt5' => 'PENDING_MANUAL_VALIDATION',
            'mock_terminals' => true,
        ]);
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user): array
    {
        return [
            'phase' => 18,
            'safety' => FleetSafety::matrix(),
            'providers' => BrokerProvider::query()->where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'connections' => BrokerConnection::query()->where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'accounts' => FleetAccount::query()->where('user_id', $user->id)->with(['brokerAccount', 'provider'])->latest('id')->limit(100)->get(),
            'portfolios' => TradingPortfolio::query()->where('user_id', $user->id)->with('memberships')->latest('id')->limit(50)->get(),
            'allocations' => AllocationPlan::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->latest('id')->limit(50)->get(),
            'assignments' => StrategyAssignment::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->latest('id')->limit(50)->get(),
            'risk_locks' => FleetRiskLock::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->latest('id')->limit(50)->get(),
            'emergencies' => FleetEmergencyControl::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->latest('id')->limit(20)->get(),
            'health' => FleetHealthSnapshot::query()->where('user_id', $user->id)->where('scope', 'FLEET')->latest('id')->first(),
            'nodes' => TradingNode::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'reconciliation' => FleetReconciliationRun::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'routes' => ExecutionRoute::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'copy_trading' => false,
            'live_auto_exists' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    public function registerProvider(User $user, array $data): BrokerProvider
    {
        $this->refuseAi($data);
        $provider = BrokerProvider::query()->create([
            'user_id' => $user->id,
            'code' => strtoupper((string) $data['code']),
            'name' => (string) $data['name'],
            'platform' => strtoupper((string) ($data['platform'] ?? 'MT5')),
            'status' => 'ACTIVE',
            'capabilities' => $data['capabilities'] ?? ['read' => true, 'demo_execute_via_phase10' => true],
            'metadata' => $data['metadata'] ?? null,
        ]);
        $this->audit($user, null, 'PROVIDER_REGISTERED', ['provider' => $provider->public_id]);

        return $provider;
    }

    /** @param array<string, mixed> $data */
    public function registerConnection(User $user, array $data): BrokerConnection
    {
        $this->refuseAi($data);
        $provider = BrokerProvider::query()->where('user_id', $user->id)->where('public_id', $data['provider_public_id'])->firstOrFail();
        $conn = BrokerConnection::query()->create([
            'user_id' => $user->id,
            'broker_provider_id' => $provider->id,
            'name' => (string) $data['name'],
            'endpoint_mode' => strtoupper((string) ($data['endpoint_mode'] ?? 'MOCK')),
            'status' => 'DISCONNECTED',
            'health' => 'UNKNOWN',
            'secret_ref' => ['ref' => (string) ($data['secret_ref'] ?? 'env:MT5_BRIDGE'), 'raw_secret_stored' => false],
            'metadata' => $data['metadata'] ?? ['worker_isolation' => true],
        ]);
        $this->audit($user, null, 'CONNECTION_REGISTERED', ['connection' => $conn->public_id]);

        return $conn;
    }

    /** @param array<string, mixed> $data */
    public function registerFleetAccount(User $user, array $data): FleetAccount
    {
        $this->refuseAi($data);
        $provider = BrokerProvider::query()->where('user_id', $user->id)->where('public_id', $data['provider_public_id'])->firstOrFail();
        $brokerAccount = BrokerAccount::query()->where('user_id', $user->id)->where('public_id', $data['broker_account_public_id'])->firstOrFail();

        $env = strtoupper((string) ($data['environment'] ?? $brokerAccount->environment?->value ?? 'DEMO'));
        if (in_array($env, ['LIVE', 'REAL', 'UNKNOWN'], true)) {
            throw ValidationException::withMessages(['environment' => 'LIVE/UNKNOWN cannot be registered for broker-changing fleet trading.']);
        }

        $connectionId = null;
        if (! empty($data['connection_public_id'])) {
            $connectionId = BrokerConnection::query()->where('user_id', $user->id)->where('public_id', $data['connection_public_id'])->value('id');
        }

        $fleet = FleetAccount::query()->create([
            'user_id' => $user->id,
            'broker_provider_id' => $provider->id,
            'broker_connection_id' => $connectionId,
            'broker_account_id' => $brokerAccount->id,
            'display_name' => (string) ($data['display_name'] ?? $brokerAccount->name),
            'login' => $data['login'] ?? $brokerAccount->broker_login,
            'server' => $data['server'] ?? $brokerAccount->broker_server ?? $brokerAccount->server,
            'currency' => (string) ($data['currency'] ?? $brokerAccount->currency ?? 'USD'),
            'environment' => $env,
            'isolation_mode' => 'STRICT',
            'status' => 'REGISTERED',
            'trading_enabled' => false,
            'safe_mode' => false,
            'capabilities' => $data['capabilities'] ?? ['demo_via_phase10' => true],
            'metadata' => $data['metadata'] ?? null,
        ]);

        BrokerCapability::query()->create([
            'user_id' => $user->id,
            'broker_provider_id' => $provider->id,
            'fleet_account_id' => $fleet->id,
            'capability_key' => 'DEMO_EXECUTE_VIA_PHASE10',
            'supported' => true,
            'enabled' => $env === 'DEMO',
            'limits' => ['copy_trading' => false, 'live_auto' => false],
        ]);

        $brokerAccount->forceFill([
            'fleet_provider_code' => $provider->code,
            'fleet_safe_mode' => false,
        ])->save();

        $this->audit($user, $fleet, 'FLEET_ACCOUNT_REGISTERED', ['fleet_account' => $fleet->public_id]);

        return $fleet->load(['brokerAccount', 'provider']);
    }

    public function verifyAccountEnvironment(User $user, FleetAccount $fleet, bool $refresh = true): array
    {
        $this->assertOwner($user, $fleet);
        if (in_array(strtoupper($fleet->environment), ['LIVE', 'REAL', 'UNKNOWN'], true)) {
            $this->enterSafeMode($fleet, 'LIVE_OR_UNKNOWN_ENVIRONMENT');
            throw ValidationException::withMessages(['environment' => 'LIVE/UNKNOWN hard-blocked.']);
        }

        $verified = $this->mt5->verifyEnvironment($fleet->fresh(['brokerAccount']), $refresh);
        $fpHash = hash('sha256', implode('|', [
            $verified['login'],
            strtoupper($verified['server']),
            $verified['trade_mode'],
            $fleet->currency,
        ]));

        $current = AccountFingerprint::query()
            ->where('fleet_account_id', $fleet->id)
            ->where('is_current', true)
            ->first();

        if ($current && $current->fingerprint_hash !== $fpHash) {
            $this->enterSafeMode($fleet, 'FINGERPRINT_MISMATCH');
            AccountFingerprint::query()->where('fleet_account_id', $fleet->id)->update(['is_current' => false]);
            AccountFingerprint::query()->create([
                'user_id' => $user->id,
                'fleet_account_id' => $fleet->id,
                'fingerprint_hash' => $fpHash,
                'login' => $verified['login'],
                'server' => $verified['server'],
                'currency' => $fleet->currency,
                'trade_mode' => $verified['trade_mode'],
                'is_current' => true,
                'raw_snapshot' => $verified,
                'captured_at' => now(),
            ]);
            $this->audit($user, $fleet, 'FINGERPRINT_MISMATCH_SAFE_MODE', ['expected' => $current->fingerprint_hash, 'got' => $fpHash]);

            throw ValidationException::withMessages([
                'fingerprint' => 'Account fingerprint mismatch — SAFE_MODE engaged; broker-changing actions blocked.',
            ]);
        }

        if (! $current) {
            AccountFingerprint::query()->create([
                'user_id' => $user->id,
                'fleet_account_id' => $fleet->id,
                'fingerprint_hash' => $fpHash,
                'login' => $verified['login'],
                'server' => $verified['server'],
                'currency' => $fleet->currency,
                'trade_mode' => $verified['trade_mode'],
                'is_current' => true,
                'raw_snapshot' => $verified,
                'captured_at' => now(),
            ]);
        }

        $fleet->forceFill([
            'verified_trade_mode' => $verified['trade_mode'],
            'environment_verified_at' => now(),
            'status' => 'VERIFIED',
            'trading_enabled' => ($verified['broker_changing_allowed'] ?? false) && ! $fleet->safe_mode,
        ])->save();

        $this->audit($user, $fleet, 'ENVIRONMENT_VERIFIED', $verified);

        return $verified;
    }

    public function registerTerminal(User $user, FleetAccount $fleet, string $nodeLabel): FleetTerminal
    {
        $this->assertOwner($user, $fleet);
        $isolation = 'acct:'.$fleet->public_id.'|node:'.Str::slug($nodeLabel);

        return FleetTerminal::query()->create([
            'user_id' => $user->id,
            'fleet_account_id' => $fleet->id,
            'broker_connection_id' => $fleet->broker_connection_id,
            'node_label' => $nodeLabel,
            'isolation_key' => $isolation,
            'status' => 'REGISTERED',
            'supervisor_state' => 'IDLE',
            'last_seen_at' => now(),
            'metadata' => ['isolation' => 'STRICT', 'account_bound' => true],
        ]);
    }

    public function superviseTerminal(User $user, FleetTerminal $terminal): FleetTerminal
    {
        abort_unless($terminal->user_id === $user->id, 404);
        $fleet = $terminal->fleetAccount;
        if ($fleet && $fleet->safe_mode) {
            $terminal->forceFill(['supervisor_state' => 'SAFE_MODE', 'status' => 'BLOCKED'])->save();

            return $terminal;
        }
        $terminal->forceFill([
            'supervisor_state' => 'HEALTHY',
            'status' => 'ONLINE',
            'last_seen_at' => now(),
            'bound_login_verified_at' => $fleet?->environment_verified_at,
        ])->save();

        return $terminal;
    }

    /** @param array<string, mixed> $data */
    public function upsertCanonicalInstrument(array $data): CanonicalInstrument
    {
        return CanonicalInstrument::query()->updateOrCreate(
            ['symbol' => strtoupper((string) $data['symbol'])],
            [
                'asset_class' => $data['asset_class'] ?? 'FX',
                'base_currency' => $data['base_currency'] ?? null,
                'quote_currency' => $data['quote_currency'] ?? null,
                'digits' => $data['digits'] ?? 5,
                'pip_size' => $data['pip_size'] ?? 0.0001,
                'metadata' => $data['metadata'] ?? null,
            ]
        );
    }

    /** @param array<string, mixed> $data */
    public function mapBrokerInstrument(User $user, array $data): array
    {
        $this->refuseAi($data);
        $provider = BrokerProvider::query()->where('user_id', $user->id)->where('public_id', $data['provider_public_id'])->firstOrFail();
        $canonical = $this->upsertCanonicalInstrument(['symbol' => $data['canonical_symbol']]);
        $fleetId = null;
        if (! empty($data['fleet_account_public_id'])) {
            $fleetId = FleetAccount::query()->where('user_id', $user->id)->where('public_id', $data['fleet_account_public_id'])->value('id');
        }
        $freshUntil = now()->addMinutes((int) ($data['fresh_minutes'] ?? 15));
        $brokerInstr = BrokerInstrument::query()->updateOrCreate(
            [
                'broker_provider_id' => $provider->id,
                'broker_symbol' => (string) $data['broker_symbol'],
                'fleet_account_id' => $fleetId,
            ],
            [
                'user_id' => $user->id,
                'canonical_instrument_id' => $canonical->id,
                'status' => 'ACTIVE',
                'spec' => $data['spec'] ?? ['digits' => $canonical->digits],
                'spec_fetched_at' => now(),
                'spec_fresh_until' => $freshUntil,
                'spec_stale' => false,
            ]
        );
        $mapping = InstrumentMapping::query()->updateOrCreate(
            [
                'canonical_instrument_id' => $canonical->id,
                'broker_instrument_id' => $brokerInstr->id,
            ],
            [
                'user_id' => $user->id,
                'mapping_status' => 'ACTIVE',
                'contract_multiplier' => $data['contract_multiplier'] ?? 1,
            ]
        );

        return ['canonical' => $canonical, 'broker_instrument' => $brokerInstr, 'mapping' => $mapping, 'spec_fresh' => ! $brokerInstr->spec_stale];
    }

    public function refreshInstrumentSpecs(User $user): array
    {
        $updated = 0;
        $stale = 0;
        BrokerInstrument::query()->where('user_id', $user->id)->each(function (BrokerInstrument $instr) use (&$updated, &$stale): void {
            if ($instr->spec_fresh_until === null || $instr->spec_fresh_until->isPast()) {
                $instr->forceFill(['spec_stale' => true])->save();
                $stale++;
            } else {
                $updated++;
            }
        });

        return ['fresh' => $updated, 'stale' => $stale];
    }

    /** @param array<string, mixed> $data */
    public function createPortfolio(User $user, array $data): TradingPortfolio
    {
        $this->refuseAi($data);

        return TradingPortfolio::query()->create([
            'user_id' => $user->id,
            'name' => (string) $data['name'],
            'base_currency' => strtoupper((string) ($data['base_currency'] ?? 'USD')),
            'status' => 'ACTIVE',
            'ai_mutable' => false,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function addMembership(User $user, TradingPortfolio $portfolio, FleetAccount $fleet, string $role = 'MEMBER'): PortfolioMembership
    {
        $this->assertOwner($user, $fleet);
        abort_unless($portfolio->user_id === $user->id, 404);

        return PortfolioMembership::query()->updateOrCreate(
            ['trading_portfolio_id' => $portfolio->id, 'fleet_account_id' => $fleet->id],
            ['user_id' => $user->id, 'role' => $role, 'active' => true]
        );
    }

    /**
     * Deterministic versioned allocation. AI cannot author active plans.
     *
     * @param array<string, float|int> $weights account_public_id => weight
     */
    public function activateAllocation(User $user, TradingPortfolio $portfolio, array $weights, bool $aiAuthored = false): AllocationPlan
    {
        abort_unless($portfolio->user_id === $user->id, 404);
        if ($aiAuthored) {
            FleetSafety::refuseAiMutation('allocate');
        }
        ksort($weights);
        $sum = array_sum($weights);
        if ($sum <= 0) {
            throw ValidationException::withMessages(['weights' => 'Allocation weights must sum to > 0.']);
        }
        $normalized = [];
        foreach ($weights as $k => $v) {
            $normalized[(string) $k] = round(((float) $v) / $sum, 8);
        }
        ksort($normalized);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        $version = (int) AllocationPlan::query()->where('trading_portfolio_id', $portfolio->id)->max('version') + 1;

        AllocationPlan::query()->where('trading_portfolio_id', $portfolio->id)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED']);

        $plan = AllocationPlan::query()->create([
            'user_id' => $user->id,
            'trading_portfolio_id' => $portfolio->id,
            'version' => $version,
            'status' => 'ACTIVE',
            'plan_hash' => $hash,
            'weights' => $normalized,
            'ai_authored' => false,
            'activated_at' => now(),
        ]);
        $this->audit($user, null, 'ALLOCATION_ACTIVATED', ['plan' => $plan->public_id, 'version' => $version, 'hash' => $hash]);

        return $plan;
    }

    /** @param array<string, mixed> $data */
    public function assignApprovedStrategy(User $user, FleetAccount $fleet, array $data): StrategyAssignment
    {
        $this->assertOwner($user, $fleet);
        $this->refuseAi($data);
        $key = (string) $data['strategy_key'];
        $version = GovernedStrategyVersion::query()
            ->where('user_id', $user->id)
            ->where('strategy_key', $key)
            ->whereIn('lifecycle_state', [StrategyLifecycleState::Approved, StrategyLifecycleState::DeployedDemo])
            ->latest('id')
            ->first();
        if ($version === null) {
            throw ValidationException::withMessages(['strategy' => 'Only Phase 16 APPROVED/DEPLOYED_DEMO strategies may be assigned.']);
        }

        StrategyAssignment::query()
            ->where('fleet_account_id', $fleet->id)
            ->where('strategy_key', $key)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'SUPERSEDED']);

        return StrategyAssignment::query()->create([
            'user_id' => $user->id,
            'trading_portfolio_id' => $data['trading_portfolio_id'] ?? null,
            'fleet_account_id' => $fleet->id,
            'governed_strategy_version_id' => $version->id,
            'strategy_key' => $key,
            'lifecycle_gate' => $version->lifecycle_state instanceof StrategyLifecycleState
                ? $version->lifecycle_state->value
                : (string) $version->lifecycle_state,
            'status' => 'ACTIVE',
            'ai_assigned' => false,
            'metadata' => ['governance_version' => $version->public_id],
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createRiskLock(User $user, array $data): FleetRiskLock
    {
        $this->refuseAi($data);
        $scope = strtoupper((string) $data['scope']);
        if (! in_array($scope, ['ACCOUNT', 'PORTFOLIO', 'GLOBAL'], true)) {
            throw ValidationException::withMessages(['scope' => 'Invalid risk lock scope.']);
        }
        $fleetId = null;
        $portfolioId = null;
        if ($scope === 'ACCOUNT') {
            $fleetId = FleetAccount::query()->where('user_id', $user->id)->where('public_id', $data['fleet_account_public_id'])->value('id');
            if (! $fleetId) {
                throw ValidationException::withMessages(['fleet_account' => 'Required for ACCOUNT scope.']);
            }
        }
        if ($scope === 'PORTFOLIO') {
            $portfolioId = TradingPortfolio::query()->where('user_id', $user->id)->where('public_id', $data['trading_portfolio_public_id'])->value('id');
            if (! $portfolioId) {
                throw ValidationException::withMessages(['portfolio' => 'Required for PORTFOLIO scope.']);
            }
        }

        return FleetRiskLock::query()->create([
            'user_id' => $user->id,
            'scope' => $scope,
            'fleet_account_id' => $fleetId,
            'trading_portfolio_id' => $portfolioId,
            'lock_code' => (string) $data['lock_code'],
            'reason' => (string) $data['reason'],
            'status' => 'ACTIVE',
            'ai_created' => false,
            'activated_at' => now(),
        ]);
    }

    public function hasBlockingRiskLock(User $user, ?FleetAccount $fleet = null, ?TradingPortfolio $portfolio = null): bool
    {
        if (FleetRiskLock::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope', 'GLOBAL')->exists()) {
            return true;
        }
        if ($portfolio && FleetRiskLock::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope', 'PORTFOLIO')->where('trading_portfolio_id', $portfolio->id)->exists()) {
            return true;
        }
        if ($fleet && FleetRiskLock::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope', 'ACCOUNT')->where('fleet_account_id', $fleet->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Deterministic account-aware router into Phase 10. No copy trading. No AI routing.
     *
     * @return array{route:ExecutionRoute,phase10_handoff:array<string,mixed>}
     */
    public function routeToPhase10(User $user, FleetAccount $fleet, TradeIntent $intent, string $idempotencyKey, bool $aiRouted = false): array
    {
        $this->assertOwner($user, $fleet);
        if ($aiRouted) {
            FleetSafety::refuseAiMutation('route');
        }
        if ($intent->broker_account_id !== $fleet->broker_account_id) {
            throw ValidationException::withMessages(['intent' => 'TradeIntent is not bound to this fleet account.']);
        }
        if ($intent->user_id !== $user->id) {
            abort(404);
        }

        $existing = ExecutionRoute::query()
            ->where('user_id', $user->id)
            ->where('fleet_account_id', $fleet->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return [
                'route' => $existing,
                'phase10_handoff' => [
                    'replayed' => true,
                    'execution_authority' => FleetSafety::EXECUTION_AUTHORITY,
                    'order_send' => false,
                    'fleet_routes_only' => true,
                ],
            ];
        }

        $decision = 'ROUTED_PHASE10';
        $reason = null;
        if ($fleet->safe_mode) {
            $decision = 'SAFE_MODE';
            $reason = $fleet->safe_mode_reason ?: 'SAFE_MODE';
        } elseif (in_array(strtoupper($fleet->environment), ['LIVE', 'REAL', 'UNKNOWN'], true)
            || in_array(strtoupper((string) $fleet->verified_trade_mode), ['LIVE', 'UNKNOWN', ''], true) && $fleet->environment === 'DEMO' && $fleet->environment_verified_at === null) {
            $decision = 'BLOCKED';
            $reason = 'LIVE_OR_UNVERIFIED';
        } elseif ($this->hasBlockingRiskLock($user, $fleet)) {
            $decision = 'BLOCKED';
            $reason = 'RISK_LOCK';
        } elseif ($this->hasActiveEmergency($user, $fleet)) {
            $decision = 'BLOCKED';
            $reason = 'EMERGENCY_CONTROL';
        } elseif ($fleet->environment !== 'DEMO') {
            $decision = 'BLOCKED';
            $reason = 'NON_DEMO_ENVIRONMENT';
        }

        $route = ExecutionRoute::query()->create([
            'user_id' => $user->id,
            'fleet_account_id' => $fleet->id,
            'trade_intent_id' => $intent->id,
            'idempotency_key' => $idempotencyKey,
            'route_decision' => $decision,
            'block_reason' => $reason,
            'copy_trading' => false,
            'ai_routed' => false,
            'payload' => [
                'broker_account_public_id' => $fleet->brokerAccount?->public_id,
                'intent_public_id' => $intent->public_id,
                'execution_authority' => FleetSafety::EXECUTION_AUTHORITY,
                'new_order_send_paths' => 0,
            ],
        ]);

        return [
            'route' => $route,
            'phase10_handoff' => [
                'replayed' => false,
                'allowed' => $decision === 'ROUTED_PHASE10',
                'execution_authority' => FleetSafety::EXECUTION_AUTHORITY,
                'order_send' => false,
                'submit_via' => 'ExecutionEngineService::submitDemo',
                'account_bound_idempotency' => $idempotencyKey,
                'copy_trading' => false,
            ],
        ];
    }

    /**
     * Correct-account Phase 11 management gate: ticket + account must match.
     *
     * @return array{allowed:bool,reason:?string}
     */
    public function assertManagementAccountBound(User $user, FleetAccount $fleet, string $ticket, ?string $expectedTicketAccountLogin = null): array
    {
        $this->assertOwner($user, $fleet);
        if ($fleet->safe_mode) {
            return ['allowed' => false, 'reason' => 'SAFE_MODE'];
        }
        if (in_array(strtoupper($fleet->environment), ['LIVE', 'REAL', 'UNKNOWN'], true)) {
            return ['allowed' => false, 'reason' => 'LIVE_UNKNOWN_HARD_BLOCK'];
        }
        if ($fleet->environment_verified_at === null) {
            return ['allowed' => false, 'reason' => 'ENVIRONMENT_NOT_VERIFIED'];
        }
        if ($expectedTicketAccountLogin !== null && $fleet->login !== null && (string) $expectedTicketAccountLogin !== (string) $fleet->login) {
            $this->enterSafeMode($fleet, 'TICKET_ACCOUNT_MISMATCH');

            return ['allowed' => false, 'reason' => 'TICKET_ACCOUNT_MISMATCH'];
        }
        if ($ticket === '') {
            return ['allowed' => false, 'reason' => 'MISSING_TICKET'];
        }

        return ['allowed' => true, 'reason' => null, 'fleet_account' => $fleet->public_id, 'ticket' => $ticket];
    }

    public function reconcileAccount(User $user, FleetAccount $fleet, bool $restartRecovery = false, array $observedPositions = []): FleetReconciliationRun
    {
        $this->assertOwner($user, $fleet);
        $run = FleetReconciliationRun::query()->create([
            'user_id' => $user->id,
            'fleet_account_id' => $fleet->id,
            'status' => 'RUNNING',
            'started_at' => now(),
            'restart_recovery' => $restartRecovery,
        ]);

        $foreign = 0;
        $matched = 0;
        $mismatches = 0;
        foreach ($observedPositions as $pos) {
            $ownerLogin = (string) ($pos['login'] ?? '');
            $owned = (string) ($pos['owned_by_nexa'] ?? '0') === '1' || ($pos['owned_by_nexa'] ?? false) === true;
            if ($ownerLogin !== '' && $fleet->login !== null && $ownerLogin !== (string) $fleet->login) {
                $foreign++;
            } elseif (! $owned) {
                $foreign++;
            } else {
                $matched++;
            }
            if (($pos['mismatch'] ?? false) === true) {
                $mismatches++;
            }
        }

        $safe = $foreign > 0 || $mismatches > 0;
        if ($safe) {
            $this->enterSafeMode($fleet, $foreign > 0 ? 'FOREIGN_POSITION_DETECTED' : 'RECON_MISMATCH');
        }

        $run->forceFill([
            'status' => 'COMPLETED',
            'foreign_positions' => $foreign,
            'matched_positions' => $matched,
            'mismatches' => $mismatches,
            'safe_mode_triggered' => $safe,
            'summary' => [
                'restart_recovery' => $restartRecovery,
                'foreign_position_safety' => $foreign === 0 ? 'OK' : 'SAFE_MODE',
            ],
            'finished_at' => now(),
        ])->save();

        $this->audit($user, $fleet, 'RECONCILIATION_COMPLETED', $run->summary ?? []);

        return $run;
    }

    public function captureFleetHealth(User $user): FleetHealthSnapshot
    {
        $accounts = FleetAccount::query()->where('user_id', $user->id)->get();
        $connections = BrokerConnection::query()->where('user_id', $user->id)->get();
        $safeModeCount = $accounts->where('safe_mode', true)->count();
        $overall = $safeModeCount > 0 ? 'DEGRADED' : ($accounts->isEmpty() ? 'EMPTY' : 'HEALTHY');
        $components = [
            'accounts' => $accounts->count(),
            'connections' => $connections->count(),
            'safe_mode_accounts' => $safeModeCount,
            'emergencies' => FleetEmergencyControl::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->count(),
            'phase_10_sole_execution' => true,
            'copy_trading' => false,
            'live_auto_exists' => false,
            'mock_terminals' => true,
            'windows_mt5' => 'PENDING_MANUAL',
        ];

        return FleetHealthSnapshot::query()->create([
            'user_id' => $user->id,
            'scope' => 'FLEET',
            'overall' => $overall,
            'components' => $components,
            'observed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function setAutomationScope(User $user, FleetAccount $fleet, array $data): AutomationAccountScope
    {
        $this->assertOwner($user, $fleet);
        $this->refuseAi($data);
        $mode = strtoupper((string) ($data['mode'] ?? 'OFF'));
        if ($mode === 'LIVE_AUTO' || str_contains($mode, 'LIVE')) {
            throw ValidationException::withMessages(['mode' => 'LIVE_AUTO does not exist and is hard-rejected.']);
        }
        if (! in_array($mode, ['OFF', 'DRY_RUN', 'DEMO_AUTO'], true)) {
            throw ValidationException::withMessages(['mode' => 'Invalid automation mode.']);
        }
        if ($fleet->safe_mode && $mode !== 'OFF') {
            throw ValidationException::withMessages(['mode' => 'Account in SAFE_MODE; automation blocked.']);
        }

        return AutomationAccountScope::query()->updateOrCreate(
            ['user_id' => $user->id, 'fleet_account_id' => $fleet->id],
            ['mode' => $mode, 'enabled' => $mode !== 'OFF', 'metadata' => ['account_scoped' => true, 'phase' => 14]]
        );
    }

    /** @param array<string, mixed> $data */
    public function emergency(User $user, array $data): FleetEmergencyControl
    {
        $this->refuseAi($data);
        $scope = strtoupper((string) $data['scope']);
        $action = strtoupper((string) $data['action']);
        $fleetId = null;
        if ($scope === 'ACCOUNT') {
            $fleet = FleetAccount::query()->where('user_id', $user->id)->where('public_id', $data['fleet_account_public_id'])->firstOrFail();
            $fleetId = $fleet->id;
            if (in_array($action, ['HALT', 'SAFE_MODE', 'KILL_AUTOMATION'], true)) {
                $this->enterSafeMode($fleet, 'EMERGENCY_'.$action);
                AutomationAccountScope::query()->where('fleet_account_id', $fleet->id)->update(['mode' => 'OFF', 'enabled' => false]);
            }
            if ($action === 'RESUME') {
                $fleet->forceFill(['safe_mode' => false, 'safe_mode_reason' => null, 'isolation_mode' => 'STRICT'])->save();
                $fleet->brokerAccount?->forceFill(['fleet_safe_mode' => false])->save();
            }
        }
        if ($scope === 'FLEET' && in_array($action, ['HALT', 'SAFE_MODE', 'KILL_AUTOMATION'], true)) {
            FleetAccount::query()->where('user_id', $user->id)->each(function (FleetAccount $fa) use ($action): void {
                $this->enterSafeMode($fa, 'FLEET_EMERGENCY_'.$action);
            });
            AutomationAccountScope::query()->where('user_id', $user->id)->update(['mode' => 'OFF', 'enabled' => false]);
        }

        $ctrl = FleetEmergencyControl::query()->create([
            'user_id' => $user->id,
            'scope' => $scope,
            'fleet_account_id' => $fleetId,
            'action' => $action,
            'status' => $action === 'RESUME' ? 'CLEARED' : 'ACTIVE',
            'reason' => (string) ($data['reason'] ?? $action),
            'ai_initiated' => false,
            'activated_at' => now(),
            'cleared_at' => $action === 'RESUME' ? now() : null,
        ]);
        $this->audit($user, $fleetId ? FleetAccount::query()->find($fleetId) : null, 'EMERGENCY_'.$action, ['scope' => $scope]);

        return $ctrl;
    }

    /** @return array<string, mixed> */
    public function normalizeValuation(User $user, string $from, string $to, float $amount): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return ['amount' => $amount, 'rate' => 1.0, 'from' => $from, 'to' => $to, 'source' => 'IDENTITY'];
        }
        $rateRow = FxValuationRate::query()
            ->where('base_currency', $from)
            ->where('quote_currency', $to)
            ->latest('as_of')
            ->first();
        if (! $rateRow) {
            // deterministic mock cross for analytics only
            $seed = crc32($from.$to) % 1000;
            $rate = 0.5 + ($seed / 1000);
            $rateRow = FxValuationRate::query()->create([
                'base_currency' => $from,
                'quote_currency' => $to,
                'rate' => $rate,
                'source' => 'MOCK',
                'as_of' => now(),
            ]);
        }

        return [
            'amount' => round($amount * (float) $rateRow->rate, 8),
            'rate' => (float) $rateRow->rate,
            'from' => $from,
            'to' => $to,
            'source' => $rateRow->source,
            'as_of' => $rateRow->as_of?->toIso8601String(),
            'user_scoped' => $user->id,
        ];
    }

    /** @return array<string, mixed> */
    public function portfolioAnalytics(User $user, TradingPortfolio $portfolio): array
    {
        abort_unless($portfolio->user_id === $user->id, 404);
        $members = PortfolioMembership::query()->where('trading_portfolio_id', $portfolio->id)->where('active', true)->with('fleetAccount')->get();
        $exposures = [];
        $total = 0.0;
        foreach ($members as $m) {
            $fa = $m->fleetAccount;
            if (! $fa) {
                continue;
            }
            $equity = (float) ($fa->brokerAccount?->metadata['equity'] ?? 10000);
            $norm = $this->normalizeValuation($user, $fa->currency, $portfolio->base_currency, $equity);
            $exposures[] = [
                'fleet_account' => $fa->public_id,
                'currency' => $fa->currency,
                'equity_native' => $equity,
                'equity_base' => $norm['amount'],
            ];
            $total += $norm['amount'];
        }
        $plan = AllocationPlan::query()->where('trading_portfolio_id', $portfolio->id)->where('status', 'ACTIVE')->first();

        return [
            'portfolio' => $portfolio->public_id,
            'base_currency' => $portfolio->base_currency,
            'total_equity_base' => round($total, 8),
            'exposures' => $exposures,
            'active_allocation_version' => $plan?->version,
            'active_allocation_hash' => $plan?->plan_hash,
            'ai_mutable' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    public function registerNode(User $user, array $data): TradingNode
    {
        $this->refuseAi($data);

        return TradingNode::query()->updateOrCreate(
            ['user_id' => $user->id, 'node_id' => (string) $data['node_id']],
            [
                'hostname' => $data['hostname'] ?? null,
                'status' => 'REGISTERED',
                'last_heartbeat_at' => now(),
                'capabilities' => $data['capabilities'] ?? ['demo_only' => true],
                'metadata' => $data['metadata'] ?? null,
            ]
        );
    }

    public function acquireLease(User $user, TradingNode $node, FleetAccount $fleet, int $ttlSeconds = 60): TradingNodeLease
    {
        abort_unless($node->user_id === $user->id, 404);
        $this->assertOwner($user, $fleet);

        $active = TradingNodeLease::query()
            ->where('fleet_account_id', $fleet->id)
            ->where('status', 'HELD')
            ->where('expires_at', '>', now())
            ->first();

        if ($active && $active->trading_node_id !== $node->id) {
            $active->forceFill(['status' => 'SPLIT_BRAIN_BLOCKED', 'split_brain_detected' => true])->save();
            $this->enterSafeMode($fleet, 'SPLIT_BRAIN_LEASE_CONFLICT');
            throw ValidationException::withMessages(['lease' => 'Split-brain detected: another node holds the account lease.']);
        }
        if ($active && $active->trading_node_id === $node->id) {
            $active->forceFill(['expires_at' => now()->addSeconds($ttlSeconds)])->save();

            return $active;
        }

        return TradingNodeLease::query()->create([
            'user_id' => $user->id,
            'trading_node_id' => $node->id,
            'fleet_account_id' => $fleet->id,
            'lease_token' => Str::random(40),
            'status' => 'HELD',
            'acquired_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
            'split_brain_detected' => false,
        ]);
    }

    public function enterSafeMode(FleetAccount $fleet, string $reason): void
    {
        $fleet->forceFill([
            'safe_mode' => true,
            'safe_mode_reason' => $reason,
            'trading_enabled' => false,
            'isolation_mode' => 'SAFE_MODE',
            'status' => 'SAFE_MODE',
        ])->save();
        $fleet->brokerAccount?->forceFill(['fleet_safe_mode' => true])->save();
    }

    private function hasActiveEmergency(User $user, FleetAccount $fleet): bool
    {
        return FleetEmergencyControl::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($fleet): void {
                $q->where('scope', 'FLEET')
                    ->orWhere(function ($q2) use ($fleet): void {
                        $q2->where('scope', 'ACCOUNT')->where('fleet_account_id', $fleet->id);
                    });
            })
            ->exists();
    }

    private function assertOwner(User $user, FleetAccount $fleet): void
    {
        abort_unless($fleet->user_id === $user->id, 404);
    }

    /** @param array<string, mixed> $data */
    private function refuseAi(array $data): void
    {
        $actor = strtoupper((string) ($data['actor_type'] ?? 'HUMAN'));
        if ($actor === 'AI' || ($data['ai'] ?? false) === true) {
            FleetSafety::refuseAiMutation((string) ($data['ai_action'] ?? 'mutate fleet state'));
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function audit(User $user, ?FleetAccount $fleet, string $type, ?array $payload = null): void
    {
        FleetAuditEvent::query()->create([
            'user_id' => $user->id,
            'fleet_account_id' => $fleet?->id,
            'event_type' => $type,
            'actor_type' => 'HUMAN',
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
