<?php

namespace App\Http\Controllers\Api;

use App\Fleet\BrokerFleetService;
use App\Fleet\Support\FleetSafety;
use App\Http\Controllers\Controller;
use App\Models\FleetAccount;
use App\Models\FleetTerminal;
use App\Models\TradeIntent;
use App\Models\TradingNode;
use App\Models\TradingPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    public function __construct(private readonly BrokerFleetService $fleet) {}

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->fleet->healthPayload()]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fleet->dashboard($request->user())]);
    }

    public function registerProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string', 'max:32'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->registerProvider($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => FleetSafety::matrix()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function registerConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_public_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'endpoint_mode' => ['nullable', 'string', 'max:32'],
            'secret_ref' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string', 'max:32'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->registerConnection($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function registerAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_public_id' => ['required', 'string'],
            'broker_account_public_id' => ['required', 'string'],
            'connection_public_id' => ['nullable', 'string'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'login' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:8'],
            'environment' => ['nullable', 'string', 'max:20'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string', 'max:32'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->registerFleetAccount($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function verifyAccount(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $verified = $this->fleet->verifyAccountEnvironment($request->user(), $fleetAccount, true);

        return response()->json(['data' => ['account' => $fleetAccount->fresh(), 'verification' => $verified]]);
    }

    public function registerTerminal(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate(['node_label' => ['required', 'string', 'max:80']]);
        $row = $this->fleet->registerTerminal($request->user(), $fleetAccount, $data['node_label']);

        return response()->json(['data' => $row], 201);
    }

    public function superviseTerminal(Request $request, FleetTerminal $fleetTerminal): JsonResponse
    {
        $row = $this->fleet->superviseTerminal($request->user(), $fleetTerminal);

        return response()->json(['data' => $row]);
    }

    public function mapInstrument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_public_id' => ['required', 'string'],
            'canonical_symbol' => ['required', 'string', 'max:32'],
            'broker_symbol' => ['required', 'string', 'max:64'],
            'fleet_account_public_id' => ['nullable', 'string'],
            'spec' => ['nullable', 'array'],
            'fresh_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'contract_multiplier' => ['nullable', 'numeric'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->mapBrokerInstrument($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function refreshSpecs(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fleet->refreshInstrumentSpecs($request->user())]);
    }

    public function createPortfolio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_currency' => ['nullable', 'string', 'max:8'],
            'metadata' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->createPortfolio($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function addMembership(Request $request, TradingPortfolio $tradingPortfolio): JsonResponse
    {
        abort_unless($tradingPortfolio->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'fleet_account_public_id' => ['required', 'string'],
            'role' => ['nullable', 'string', 'max:32'],
        ]);
        $fleet = FleetAccount::query()->where('user_id', $request->user()->id)->where('public_id', $data['fleet_account_public_id'])->firstOrFail();
        $row = $this->fleet->addMembership($request->user(), $tradingPortfolio, $fleet, $data['role'] ?? 'MEMBER');

        return response()->json(['data' => $row], 201);
    }

    public function activateAllocation(Request $request, TradingPortfolio $tradingPortfolio): JsonResponse
    {
        abort_unless($tradingPortfolio->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'weights' => ['required', 'array', 'min:1'],
            'ai_authored' => ['nullable', 'boolean'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->activateAllocation(
                $request->user(),
                $tradingPortfolio,
                $data['weights'],
                (bool) ($data['ai_authored'] ?? false) || strtoupper((string) ($data['actor_type'] ?? '')) === 'AI'
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function assignStrategy(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'strategy_key' => ['required', 'string', 'max:80'],
            'trading_portfolio_public_id' => ['nullable', 'string'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['trading_portfolio_public_id'])) {
            $data['trading_portfolio_id'] = TradingPortfolio::query()
                ->where('user_id', $request->user()->id)
                ->where('public_id', $data['trading_portfolio_public_id'])
                ->value('id');
        }
        try {
            $row = $this->fleet->assignApprovedStrategy($request->user(), $fleetAccount, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function createRiskLock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:32'],
            'lock_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:255'],
            'fleet_account_public_id' => ['nullable', 'string'],
            'trading_portfolio_public_id' => ['nullable', 'string'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->createRiskLock($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function routeExecution(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'trade_intent_public_id' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        $intent = TradeIntent::query()->where('user_id', $request->user()->id)->where('public_id', $data['trade_intent_public_id'])->firstOrFail();
        try {
            $row = $this->fleet->routeToPhase10(
                $request->user(),
                $fleetAccount,
                $intent,
                $data['idempotency_key'],
                (bool) ($data['ai'] ?? false) || strtoupper((string) ($data['actor_type'] ?? '')) === 'AI'
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row]);
    }

    public function managementGate(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:64'],
            'ticket_account_login' => ['nullable', 'string', 'max:64'],
        ]);
        $gate = $this->fleet->assertManagementAccountBound(
            $request->user(),
            $fleetAccount,
            $data['ticket'],
            $data['ticket_account_login'] ?? null
        );

        return response()->json(['data' => $gate], $gate['allowed'] ? 200 : 403);
    }

    public function reconcile(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'restart_recovery' => ['nullable', 'boolean'],
            'observed_positions' => ['nullable', 'array'],
        ]);
        $row = $this->fleet->reconcileAccount(
            $request->user(),
            $fleetAccount,
            (bool) ($data['restart_recovery'] ?? false),
            $data['observed_positions'] ?? []
        );

        return response()->json(['data' => $row]);
    }

    public function healthCapture(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fleet->captureFleetHealth($request->user())]);
    }

    public function automationScope(Request $request, FleetAccount $fleetAccount): JsonResponse
    {
        abort_unless($fleetAccount->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'mode' => ['required', 'string', 'max:32'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->setAutomationScope($request->user(), $fleetAccount, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row]);
    }

    public function emergency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:32'],
            'action' => ['required', 'string', 'max:48'],
            'reason' => ['nullable', 'string', 'max:255'],
            'fleet_account_public_id' => ['nullable', 'string'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->emergency($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function valuation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:8'],
            'to' => ['required', 'string', 'max:8'],
            'amount' => ['required', 'numeric'],
        ]);

        return response()->json(['data' => $this->fleet->normalizeValuation(
            $request->user(),
            $data['from'],
            $data['to'],
            (float) $data['amount']
        )]);
    }

    public function portfolioAnalytics(Request $request, TradingPortfolio $tradingPortfolio): JsonResponse
    {
        return response()->json(['data' => $this->fleet->portfolioAnalytics($request->user(), $tradingPortfolio)]);
    }

    public function registerNode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'string', 'max:80'],
            'hostname' => ['nullable', 'string', 'max:160'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string'],
            'ai' => ['nullable', 'boolean'],
        ]);
        try {
            $row = $this->fleet->registerNode($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $row], 201);
    }

    public function acquireLease(Request $request, TradingNode $tradingNode): JsonResponse
    {
        abort_unless($tradingNode->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'fleet_account_public_id' => ['required', 'string'],
            'ttl_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
        ]);
        $fleet = FleetAccount::query()->where('user_id', $request->user()->id)->where('public_id', $data['fleet_account_public_id'])->firstOrFail();
        $row = $this->fleet->acquireLease($request->user(), $tradingNode, $fleet, (int) ($data['ttl_seconds'] ?? 60));

        return response()->json(['data' => $row], 201);
    }

    public function refuseAiRoute(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'AI cannot route, allocate, or change risk.',
            'data' => FleetSafety::matrix(),
        ], 403);
    }

    public function refuseLiveAuto(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'LIVE_AUTO does not exist.',
            'data' => ['live_auto_exists' => false],
        ], 403);
    }

    public function refuseCopyTrading(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Copy trading is absent from Phase 18.',
            'data' => ['copy_trading' => false],
        ], 403);
    }
}
