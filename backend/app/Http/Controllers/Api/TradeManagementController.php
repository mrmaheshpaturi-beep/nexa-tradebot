<?php

namespace App\Http\Controllers\Api;

use App\Enums\CloseReason;
use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementDecisionType;
use App\Enums\PositionManagementActionType;
use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Http\Controllers\Controller;
use App\Models\ManagedPosition;
use App\Models\ManagementConfirmation;
use App\Models\PositionManagementAction;
use App\Models\TradeManagementDecision;
use App\Models\TradeManagementEvent;
use App\Models\TradeManagementPolicy;
use App\Models\TradeSummary;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\TradeManagement\ManagementConfirmationService;
use App\TradeManagement\ManagementCrashRecoveryService;
use App\TradeManagement\ManagementActionService;
use App\TradeManagement\PositionMonitorService;
use App\TradeManagement\TradeManagementEngineService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TradeManagementController extends Controller
{
    public function __construct(
        private readonly TradeManagementEngineService $engine,
        private readonly ManagementActionService $actions,
        private readonly ManagementConfirmationService $confirmations,
        private readonly ManagementCrashRecoveryService $recovery,
        private readonly PositionMonitorService $monitor,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function status(Request $request)
    {
        $user = $request->user();
        $open = ManagedPosition::query()
            ->where('user_id', $user->id)
            ->where('ownership', PositionOwnership::NexaManaged)
            ->whereNotIn('management_status', ['CLOSED', 'FOREIGN_IGNORED'])
            ->count();

        return response()->json(['data' => [
            'phase' => 11,
            'engine' => 'TradeManagementEngine',
            'status' => 'READY',
            'managed_open' => $open,
            'allow_demo_execution' => (bool) $this->settings->value('allow_demo_execution'),
            'allow_live_execution' => false,
            'auto_demo_execution' => false,
            'global_auto_management' => (bool) ($this->settings->value('global_auto_management') ?? true),
            'live_modification' => 'HARD_BLOCKED',
            'live_partial_close' => 'HARD_BLOCKED',
            'live_full_close' => 'HARD_BLOCKED',
            'order_send_location' => 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
        ]]);
    }

    public function health(Request $request)
    {
        return response()->json(['data' => [
            'phase' => 11,
            'status' => 'OK',
            'demo_only' => true,
            'foreign_positions_protected' => true,
            'blind_retry' => false,
            'order_send' => 'AUTHORIZED_DEMO_PATH_ONLY',
            'order_send_location' => 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
            'checks' => [
                'live_modification' => 'HARD_BLOCKED',
                'live_partial_close' => 'HARD_BLOCKED',
                'live_full_close' => 'HARD_BLOCKED',
                'ci_fake_bridge' => config('trading_bridge.demo_client') !== 'http',
            ],
        ]]);
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $base = ManagedPosition::query()->where('user_id', $user->id)->where('ownership', PositionOwnership::NexaManaged);
        $open = (clone $base)->whereNotIn('management_status', ['CLOSED', 'FOREIGN_IGNORED'])->get();

        return response()->json(['data' => [
            'cards' => [
                'managed_positions' => $open->count(),
                'open_demo_positions' => $open->where('environment', TradingEnvironment::Demo)->count(),
                'floating_pnl' => $open->sum(fn ($p) => (float) $p->floating_profit),
                'protected_positions' => $open->where('management_status', 'PROTECTING')->count(),
                'break_even_applied' => $open->where('break_even_applied', true)->count(),
                'trailing_active' => $open->where('trailing_active', true)->count(),
                'partial_targets_pending' => $open->sum(fn ($p) => $p->positionTargets()->where('status', 'PENDING')->count()),
                'engine_status' => 'READY',
            ],
            'positions' => $open->take(50)->map(fn ($p) => $this->serializePosition($p))->values(),
            'recent_decisions' => TradeManagementDecision::query()
                ->where('user_id', $user->id)
                ->latest('decided_at')
                ->limit(20)
                ->get()
                ->map(fn ($d) => $this->serializeDecision($d)),
        ]]);
    }

    public function positions(Request $request)
    {
        $rows = ManagedPosition::query()
            ->where('user_id', $request->user()->id)
            ->with(['positionTargets', 'policy'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($p) => $this->serializePosition($p));

        return response()->json(['data' => $rows]);
    }

    public function showPosition(Request $request, ManagedPosition $position)
    {
        abort_unless($position->user_id === $request->user()->id, 404);

        return response()->json(['data' => [
            'position' => $this->serializePosition($position->load(['positionTargets', 'policy', 'summary'])),
            'events' => $position->events()->latest('occurred_at')->limit(50)->get(),
            'decisions' => $position->decisions()->latest('decided_at')->limit(20)->get()->map(fn ($d) => $this->serializeDecision($d)),
            'actions' => $position->actions()->latest('id')->limit(20)->get(),
            'why' => $position->decisions()->latest('decided_at')->value('why'),
            'chart_markers' => $this->chartMarkers($position),
        ]]);
    }

    public function pause(Request $request, ManagedPosition $position)
    {
        abort_unless($position->user_id === $request->user()->id, 404);
        $updated = $this->engine->pause($position);
        $this->audit->record('trade_management.paused', $updated, [], ['status' => 'PAUSED'], $request);

        return response()->json(['data' => $this->serializePosition($updated)]);
    }

    public function resume(Request $request, ManagedPosition $position)
    {
        abort_unless($position->user_id === $request->user()->id, 404);
        $updated = $this->engine->resume($position);
        $this->audit->record('trade_management.resumed', $updated, [], ['status' => 'MANAGING'], $request);

        return response()->json(['data' => $this->serializePosition($updated)]);
    }

    public function evaluate(Request $request, ManagedPosition $position)
    {
        abort_unless($position->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'auto_execute' => ['sometimes', 'boolean'],
            'strategy_invalidated' => ['sometimes', 'boolean'],
            'session_closing' => ['sometimes', 'boolean'],
            'weekend_imminent' => ['sometimes', 'boolean'],
            'emergency' => ['sometimes', 'boolean'],
            'atr' => ['sometimes', 'numeric'],
            'structure_stop' => ['sometimes', 'numeric'],
            'risk' => ['sometimes', 'array'],
            'extras' => ['sometimes', 'array'],
        ]);
        $result = $this->engine->evaluate($position, $request->user(), $data, (bool) ($data['auto_execute'] ?? true));

        return response()->json(['data' => [
            'decision' => $this->serializeDecision($result['decision']),
            'action' => $result['action'],
        ]]);
    }

    public function prepareClose(Request $request, ManagedPosition $position)
    {
        return $this->prepareManual($request, $position, PositionManagementActionType::FullClose, [
            'close_volume' => (float) $position->current_volume,
            'close_reason' => CloseReason::Manual->value,
        ]);
    }

    public function confirmClose(Request $request, ManagedPosition $position)
    {
        return $this->confirmManual($request, $position, ManagementDecisionType::FullClose);
    }

    public function preparePartial(Request $request, ManagedPosition $position)
    {
        $data = $request->validate([
            'volume' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        return $this->prepareManual($request, $position, PositionManagementActionType::PartialClose, [
            'close_volume' => (float) $data['volume'],
        ], $data['idempotency_key']);
    }

    public function confirmPartial(Request $request, ManagedPosition $position)
    {
        return $this->confirmManual($request, $position, ManagementDecisionType::PartialClose);
    }

    public function prepareProtection(Request $request, ManagedPosition $position)
    {
        $data = $request->validate([
            'stop_loss' => ['nullable', 'numeric'],
            'take_profit' => ['nullable', 'numeric'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        return $this->prepareManual($request, $position, PositionManagementActionType::ModifySlTp, [
            'stop_loss' => $data['stop_loss'] ?? null,
            'take_profit' => $data['take_profit'] ?? null,
        ], $data['idempotency_key']);
    }

    public function confirmProtection(Request $request, ManagedPosition $position)
    {
        return $this->confirmManual($request, $position, ManagementDecisionType::MoveStop);
    }

    public function events(Request $request, ManagedPosition $position)
    {
        abort_unless($position->user_id === $request->user()->id, 404);

        return response()->json([
            'data' => TradeManagementEvent::query()
                ->where('managed_position_id', $position->id)
                ->latest('occurred_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function policies(Request $request)
    {
        $rows = TradeManagementPolicy::query()
            ->where(function ($q) use ($request): void {
                $q->whereNull('user_id')->orWhere('user_id', $request->user()->id);
            })
            ->orderByDesc('version')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function monitorTick(Request $request)
    {
        $results = $this->monitor->tick($request->user());

        return response()->json(['data' => [
            'evaluated' => count($results),
            'results' => collect($results)->map(fn ($r) => [
                'decision' => $this->serializeDecision($r['decision']),
                'action_public_id' => $r['action']?->public_id,
            ]),
        ]]);
    }

    public function recover(Request $request, PositionManagementAction $action)
    {
        abort_unless($action->user_id === $request->user()->id, 404);
        $recovered = $this->recovery->recoverOne($action);
        $this->audit->record('trade_management.recovered', $recovered, [], ['status' => $recovered->status->value], $request);

        return response()->json(['data' => $recovered]);
    }

    public function recoverAll(Request $request)
    {
        $rows = $this->recovery->recoverUnknown();

        return response()->json(['data' => ['recovered' => count($rows)]]);
    }

    public function closeAll(Request $request)
    {
        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
            'demo_verified' => ['required', 'accepted'],
        ]);
        $results = $this->engine->closeAllNexaManagedDemo($request->user(), (bool) $data['confirm']);
        $this->audit->record('trade_management.close_all', $request->user(), [], ['count' => count($results)], $request);

        return response()->json(['data' => [
            'closed' => count($results),
            'results' => collect($results)->map(fn ($r) => [
                'decision' => $this->serializeDecision($r['decision']),
                'action' => $r['action']?->public_id,
            ]),
        ]]);
    }

    public function summaries(Request $request)
    {
        $rows = TradeSummary::query()
            ->where('user_id', $request->user()->id)
            ->latest('finalized_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function prepareManual(
        Request $request,
        ManagedPosition $position,
        PositionManagementActionType $type,
        array $preview,
        ?string $idempotencyKey = null,
    ) {
        abort_unless($position->user_id === $request->user()->id, 404);
        if ($position->ownership !== PositionOwnership::NexaManaged) {
            throw ValidationException::withMessages(['ownership' => 'Foreign positions cannot be managed.']);
        }
        $key = $idempotencyKey ?? $request->validate(['idempotency_key' => ['required', 'string', 'max:120']])['idempotency_key'];
        $result = $this->confirmations->prepare($request->user(), $position, $type, $key, $preview);

        return response()->json(['data' => [
            'confirmation' => $result['confirmation'],
            'challenge_token' => $result['challenge_token'],
            'preview' => $preview,
        ]], 201);
    }

    private function confirmManual(Request $request, ManagedPosition $position, ManagementDecisionType $decisionType)
    {
        abort_unless($position->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'confirmation_public_id' => ['required', 'string'],
            'challenge_token' => ['required_without:confirm_token', 'string'],
            'confirm_token' => ['sometimes', 'string'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'step' => ['sometimes', 'integer', 'in:2,3'],
        ]);

        $confirmation = ManagementConfirmation::query()
            ->where('public_id', $data['confirmation_public_id'])
            ->where('user_id', $request->user()->id)
            ->where('managed_position_id', $position->id)
            ->firstOrFail();

        if (empty($data['confirm_token'])) {
            $step2 = $this->confirmations->confirm($confirmation, $data['challenge_token']);

            return response()->json(['data' => [
                'confirmation' => $step2['confirmation'],
                'confirm_token' => $step2['confirm_token'],
            ]]);
        }

        $this->confirmations->assertConsumable($confirmation, $data['confirm_token']);
        $preview = $confirmation->preview_payload ?? [];
        $decision = TradeManagementDecision::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $request->user()->id,
            'decision_type' => $decisionType,
            'status' => ManagementDecisionStatus::Approved,
            'rule_code' => 'MANUAL',
            'priority' => 15,
            'why' => 'Manual DEMO management action (two-step confirmed).',
            'proposed_sl' => $preview['stop_loss'] ?? null,
            'proposed_tp' => $preview['take_profit'] ?? null,
            'proposed_close_volume' => $preview['close_volume'] ?? null,
            'payload' => [
                'protective' => in_array($decisionType, [
                    ManagementDecisionType::FullClose,
                    ManagementDecisionType::MoveBreakEven,
                    ManagementDecisionType::TrailStop,
                ], true),
                'close_reason' => $preview['close_reason'] ?? CloseReason::Manual->value,
                'manual' => true,
            ],
            'decided_at' => now(),
        ]);

        $result = $this->actions->executeDecision($decision, $request->user(), $data['idempotency_key'], $confirmation);
        $this->audit->record('trade_management.manual_action', $result['action'], [], [
            'type' => $decisionType->value,
            'replayed' => $result['replayed'],
        ], $request);

        return response()->json(['data' => [
            'decision' => $this->serializeDecision($decision->fresh()),
            'action' => $result['action'],
            'replayed' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }

    private function serializePosition(ManagedPosition $p): array
    {
        return [
            'public_id' => $p->public_id,
            'symbol' => $p->symbol,
            'direction' => $p->direction?->value ?? $p->direction,
            'environment' => $p->environment?->value ?? $p->environment,
            'ownership' => $p->ownership?->value ?? $p->ownership,
            'management_status' => $p->management_status?->value ?? $p->management_status,
            'broker_position_id' => $p->broker_position_id,
            'initial_volume' => $p->initial_volume,
            'current_volume' => $p->current_volume,
            'entry_price' => $p->entry_price,
            'current_stop_loss' => $p->current_stop_loss,
            'current_take_profit' => $p->current_take_profit,
            'floating_profit' => $p->floating_profit,
            'r_multiple' => $p->r_multiple,
            'mae' => $p->mae,
            'mfe' => $p->mfe,
            'break_even_applied' => $p->break_even_applied,
            'trailing_active' => $p->trailing_active,
            'auto_management_paused' => $p->auto_management_paused,
            'close_reason' => $p->close_reason?->value ?? $p->close_reason,
            'opened_at' => $p->opened_at,
            'closed_at' => $p->closed_at,
            'targets' => $p->relationLoaded('positionTargets') ? $p->positionTargets : null,
            'policy_version' => $p->management_policy_version,
            'why' => $p->decisions()->latest('decided_at')->value('why'),
        ];
    }

    private function serializeDecision(TradeManagementDecision $d): array
    {
        return [
            'public_id' => $d->public_id,
            'decision_type' => $d->decision_type?->value ?? $d->decision_type,
            'status' => $d->status?->value ?? $d->status,
            'rule_code' => $d->rule_code,
            'priority' => $d->priority,
            'why' => $d->why,
            'proposed_sl' => $d->proposed_sl,
            'proposed_tp' => $d->proposed_tp,
            'proposed_close_volume' => $d->proposed_close_volume,
            'decided_at' => $d->decided_at,
            'managed_position_id' => $d->managed_position_id,
        ];
    }

    private function chartMarkers(ManagedPosition $position): array
    {
        $markers = [
            ['type' => 'ENTRY', 'price' => $position->entry_price, 'at' => $position->opened_at],
        ];
        if ($position->break_even_applied) {
            $markers[] = ['type' => 'BREAK_EVEN', 'price' => $position->current_stop_loss, 'at' => $position->break_even_applied_at];
        }
        if ($position->trailing_active) {
            $markers[] = ['type' => 'TRAIL', 'price' => $position->last_trail_stop, 'at' => $position->last_managed_at];
        }
        foreach ($position->positionTargets as $t) {
            $markers[] = ['type' => $t->label, 'price' => $t->price, 'status' => $t->status?->value, 'at' => $t->hit_at];
        }
        if ($position->closed_at) {
            $markers[] = ['type' => 'EXIT', 'price' => $position->metadata['exit_price'] ?? null, 'at' => $position->closed_at, 'reason' => $position->close_reason];
        }

        return $markers;
    }
}
