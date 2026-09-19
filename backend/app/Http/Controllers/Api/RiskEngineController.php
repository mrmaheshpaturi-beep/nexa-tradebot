<?php

namespace App\Http\Controllers\Api;

use App\Enums\RiskLockType;
use App\Enums\RiskReasonCode;
use App\Http\Controllers\Controller;
use App\Models\BrokerAccount;
use App\Models\ProposedPlan;
use App\Models\RiskDecision;
use App\Models\RiskLock;
use App\Models\RiskReservation;
use App\Models\TradeIntent;
use App\Services\AuditService;
use App\Services\ExecutionGate;
use App\Services\RiskAccountContextBuilder;
use App\Services\RiskEngineService;
use App\Services\RiskLockService;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskEngineController extends Controller
{
    public function __construct(
        private readonly RiskEngineService $engine,
        private readonly RiskLockService $locks,
        private readonly RiskAccountContextBuilder $accountContext,
        private readonly TradeLifecycleService $lifecycle,
        private readonly ExecutionGate $gate,
        private readonly AuditService $audit,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->engine->health()]);
    }

    public function catalog(): JsonResponse
    {
        $health = $this->engine->health();

        return response()->json(['data' => [
            'phase' => 9,
            'engine_version' => $health['engine_version'],
            'rules_bundle_version' => $health['rules_bundle_version'],
            'rules' => $health['rules'],
            'execution' => [
                'order_send' => false,
                'demo_execution' => false,
                'live_execution' => false,
                'broker_routing' => false,
            ],
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = $user->brokerAccounts()->with('riskProfile')->where('is_enabled', true)->first()
            ?? $user->brokerAccounts()->with('riskProfile')->first();

        $context = null;
        if ($account && $account->riskProfile) {
            $instrument = \App\Models\TradingInstrument::query()->where('is_enabled', true)->first();
            if ($instrument) {
                $context = $this->accountContext->build($account, $instrument);
            }
        }

        return response()->json(['data' => [
            'phase' => 9,
            'engine' => $this->engine->health(),
            'profile' => $account?->riskProfile,
            'account' => $account,
            'account_context' => $context,
            'active_locks' => RiskLock::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderByDesc('locked_at')
                ->limit(20)
                ->get(),
            'recent_decisions' => RiskDecision::query()
                ->whereHas('tradeIntent', fn ($q) => $q->where('user_id', $user->id))
                ->with(['proposedPlan', 'tradeIntent'])
                ->latest('evaluated_at')
                ->limit(20)
                ->get(),
            'active_reservations' => RiskReservation::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->latest('id')
                ->limit(20)
                ->get(),
            'execution' => [
                'order_send' => false,
                'demo_execution' => false,
                'live_execution' => false,
                'mt5_execution' => 'DISABLED',
            ],
        ]]);
    }

    public function decisions(Request $request): JsonResponse
    {
        $rows = RiskDecision::query()
            ->whereHas('tradeIntent', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['proposedPlan', 'riskProfile', 'tradeIntent.instrument'])
            ->latest('evaluated_at')
            ->paginate(25);

        return response()->json($rows);
    }

    public function showDecision(Request $request, RiskDecision $riskDecision): JsonResponse
    {
        abort_unless($riskDecision->tradeIntent?->user_id === $request->user()->id, 404);

        return response()->json(['data' => $riskDecision->load(['proposedPlan', 'riskProfile', 'tradeIntent.instrument', 'reservation'])]);
    }

    public function showPlan(Request $request, ProposedPlan $proposedPlan): JsonResponse
    {
        abort_unless($proposedPlan->tradeIntent?->user_id === $request->user()->id, 404);

        return response()->json(['data' => $proposedPlan->load(['riskDecision', 'instrument'])]);
    }

    public function evaluate(Request $request, TradeIntent $tradeIntent): JsonResponse
    {
        abort_unless($tradeIntent->user_id === $request->user()->id, 404);
        $intent = $this->lifecycle->evaluate($tradeIntent, $request);

        return response()->json(['data' => $intent->load(['riskDecision.proposedPlan', 'instrument', 'brokerAccount'])]);
    }

    public function locks(Request $request): JsonResponse
    {
        $rows = RiskLock::query()
            ->where('user_id', $request->user()->id)
            ->latest('locked_at')
            ->paginate(25);

        return response()->json($rows);
    }

    public function createLock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lock_type' => ['required', Rule::enum(RiskLockType::class)],
            'reason_code' => ['required', Rule::enum(RiskReasonCode::class)],
            'message' => ['required', 'string', 'max:500'],
            'account_public_id' => ['nullable', 'string'],
        ]);
        $account = null;
        if (! empty($data['account_public_id'])) {
            $account = $request->user()->brokerAccounts()->where('public_id', $data['account_public_id'])->firstOrFail();
        }
        $lock = $this->locks->ensureLock(
            $request->user(),
            RiskLockType::from($data['lock_type']),
            RiskReasonCode::from($data['reason_code']),
            $data['message'],
            $account,
            $account?->riskProfile,
            ['source' => 'manual'],
            $request->user(),
        );
        $this->audit->record('risk_lock.created', $lock, [], $lock->toArray(), $request);

        return response()->json(['data' => $lock], 201);
    }

    public function releaseLock(Request $request, RiskLock $riskLock): JsonResponse
    {
        abort_unless($riskLock->user_id === $request->user()->id, 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $lock = $this->locks->release($riskLock, $request->user(), $data['note'] ?? 'Manual release');
        $this->audit->record('risk_lock.released', $lock, [], $lock->toArray(), $request);

        return response()->json(['data' => $lock]);
    }

    public function assertGate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_public_id' => ['required', 'string'],
            'environment' => ['required', 'string'],
        ]);
        $account = BrokerAccount::query()->where('public_id', $data['account_public_id'])->firstOrFail();
        abort_unless($account->user_id === $request->user()->id, 404);

        try {
            $this->gate->assertCanExecute($data['environment'], $account);

            return response()->json(['data' => [
                'allowed' => true,
                'environment' => $data['environment'],
                'order_send' => false,
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'data' => [
                    'allowed' => false,
                    'environment' => $data['environment'],
                    'order_send' => false,
                    'errors' => $e->errors(),
                ],
            ], 422);
        }
    }
}
