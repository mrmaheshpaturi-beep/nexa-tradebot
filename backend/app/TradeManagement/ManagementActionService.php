<?php

namespace App\TradeManagement;

use App\Contracts\DemoBridgeClient;
use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementStatus;
use App\Enums\PositionManagementActionStatus;
use App\Enums\PositionManagementActionType;
use App\Enums\TargetHitStatus;
use App\Execution\Mt5OrderRequestBuilder;
use App\Models\ManagedPosition;
use App\Models\ManagementConfirmation;
use App\Models\PositionManagementAction;
use App\Models\TradeManagementDecision;
use App\Models\TradeManagementEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes DEMO management actions via authorized adapter methods only.
 * No blind retry. UNKNOWN → reconcile.
 */
class ManagementActionService
{
    public function __construct(
        private readonly PositionManagementGate $gate,
        private readonly DemoBridgeClient $bridge,
        private readonly Mt5OrderRequestBuilder $requests,
        private readonly ManagementActionLockService $locks,
        private readonly ManagementReconciliationService $reconciliation,
        private readonly TradeSummaryService $summaries,
    ) {}

    /**
     * @return array{action:PositionManagementAction,replayed:bool}
     */
    public function executeDecision(
        TradeManagementDecision $decision,
        User $user,
        string $idempotencyKey,
        ?ManagementConfirmation $confirmation = null,
    ): array {
        $existing = PositionManagementAction::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return ['action' => $existing, 'replayed' => true];
        }

        /** @var ManagedPosition $position */
        $position = $decision->managedPosition()->with(['brokerAccount', 'policy', 'positionTargets'])->firstOrFail();
        $protective = (bool) (($decision->payload['protective'] ?? false)
            || in_array($decision->decision_type->value, ['FULL_CLOSE', 'MOVE_BREAK_EVEN', 'TRAIL_STOP', 'MOVE_STOP'], true));

        $verification = $this->gate->assertCanManage($position, $protective);
        $lock = $this->locks->acquire($user, $position);

        try {
            return DB::transaction(function () use ($decision, $user, $idempotencyKey, $position, $verification, $lock, $protective): array {
                $position = ManagedPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
                $actionType = $this->mapActionType($decision);
                $quote = $this->bridge->freshQuote($position->symbol);
                $spec = $this->bridge->freshSymbolSpec($position->symbol);
                $source = strtoupper((string) ($quote['source'] ?? ''));
                if ($source === 'MOCK' || str_contains($source, 'SIMULATION')) {
                    throw ValidationException::withMessages(['quote' => 'Refusing silent mock prices under MT5 DEMO label.']);
                }

                $action = PositionManagementAction::query()->create([
                    'decision_id' => $decision->id,
                    'managed_position_id' => $position->id,
                    'user_id' => $user->id,
                    'broker_account_id' => $position->broker_account_id,
                    'action_type' => $actionType,
                    'status' => PositionManagementActionStatus::Locked,
                    'idempotency_key' => $idempotencyKey,
                    'requested_sl' => $decision->proposed_sl,
                    'requested_tp' => $decision->proposed_tp,
                    'requested_close_volume' => $decision->proposed_close_volume,
                    'blind_retry_forbidden' => true,
                    'created_at_action' => now(),
                    'verification_snapshot' => $verification,
                ]);
                $lock->forceFill(['position_management_action_id' => $action->id])->save();

                $request = $this->buildRequest($actionType, $position, $decision, $quote, $verification, $action, $spec);
                $nonce = (string) Str::uuid();
                $correlation = (string) Str::uuid();
                $action->forceFill([
                    'status' => PositionManagementActionStatus::Submitted,
                    'submitted_at' => now(),
                    'bridge_nonce' => $nonce,
                    'bridge_correlation_id' => $correlation,
                    'request_snapshot' => $request,
                    'order_check_passed' => true,
                ])->save();

                try {
                    $response = $this->dispatch($actionType, $request, $idempotencyKey, $nonce, $correlation);
                } catch (Throwable $e) {
                    $action->forceFill([
                        'status' => PositionManagementActionStatus::TimeoutUnknown,
                        'unknown_reason' => 'EXCEPTION',
                        'response_snapshot' => ['error' => $e->getMessage()],
                    ])->save();
                    $decision->forceFill(['status' => ManagementDecisionStatus::Unknown])->save();
                    $this->event($position, $decision, $action, 'MANAGEMENT_UNKNOWN', ['error' => $e->getMessage()]);
                    // No blind retry — reconcile.
                    $this->reconciliation->reconcileAction($action);

                    return ['action' => $action->fresh(), 'replayed' => false];
                }

                $outcome = (string) ($response['outcome'] ?? 'REJECTED');
                if ($outcome === 'TIMEOUT_UNKNOWN') {
                    $action->forceFill([
                        'status' => PositionManagementActionStatus::TimeoutUnknown,
                        'unknown_reason' => 'TIMEOUT',
                        'retcode' => $response['retcode'] ?? null,
                        'response_snapshot' => $response,
                    ])->save();
                    $decision->forceFill(['status' => ManagementDecisionStatus::Unknown])->save();
                    $this->event($position, $decision, $action, 'MANAGEMENT_TIMEOUT_UNKNOWN', $response);
                    $this->reconciliation->reconcileAction($action);

                    return ['action' => $action->fresh(), 'replayed' => false];
                }

                if ($outcome === 'REJECTED' || ($response['send'] ?? null) === null) {
                    $action->forceFill([
                        'status' => PositionManagementActionStatus::Rejected,
                        'retcode' => $response['retcode'] ?? null,
                        'response_snapshot' => $response,
                        'completed_at' => now(),
                    ])->save();
                    $decision->forceFill(['status' => ManagementDecisionStatus::Failed])->save();
                    $this->event($position, $decision, $action, 'MANAGEMENT_REJECTED', $response);

                    return ['action' => $action->fresh(), 'replayed' => false];
                }

                $this->applySuccess($position, $decision, $action, $response, $protective);
                $this->event($position, $decision, $action, 'MANAGEMENT_COMPLETED', [
                    'action_type' => $actionType->value,
                    'why' => $decision->why,
                ]);

                return ['action' => $action->fresh(), 'replayed' => false];
            });
        } finally {
            $this->locks->release($lock);
        }
    }

    private function mapActionType(TradeManagementDecision $decision): PositionManagementActionType
    {
        return match ($decision->decision_type->value) {
            'MOVE_STOP', 'MOVE_BREAK_EVEN', 'TRAIL_STOP' => PositionManagementActionType::ModifySl,
            'UPDATE_TP' => PositionManagementActionType::ModifyTp,
            'PARTIAL_CLOSE' => PositionManagementActionType::PartialClose,
            'FULL_CLOSE' => PositionManagementActionType::FullClose,
            'CANCEL_PENDING' => PositionManagementActionType::CancelPending,
            default => throw ValidationException::withMessages(['decision' => 'Decision does not map to a broker action.']),
        };
    }

    /**
     * @param  array<string,mixed>  $quote
     * @param  array<string,mixed>  $verification
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function buildRequest(
        PositionManagementActionType $type,
        ManagedPosition $position,
        TradeManagementDecision $decision,
        array $quote,
        array $verification,
        PositionManagementAction $action,
        array $spec,
    ): array {
        $side = $position->direction->value;
        $pid = (string) $position->broker_position_id;
        return match ($type) {
            PositionManagementActionType::ModifySl => $this->requests->buildModifyProtection(
                $position->symbol, $pid, $side,
                $decision->proposed_sl !== null ? (float) $decision->proposed_sl : null,
                $position->current_take_profit !== null ? (float) $position->current_take_profit : null,
                $verification, $action->public_id,
            ),
            PositionManagementActionType::ModifyTp => $this->requests->buildModifyProtection(
                $position->symbol, $pid, $side,
                $position->current_stop_loss !== null ? (float) $position->current_stop_loss : null,
                $decision->proposed_tp !== null ? (float) $decision->proposed_tp : null,
                $verification, $action->public_id,
            ),
            PositionManagementActionType::ModifySlTp => $this->requests->buildModifyProtection(
                $position->symbol, $pid, $side,
                $decision->proposed_sl !== null ? (float) $decision->proposed_sl : null,
                $decision->proposed_tp !== null ? (float) $decision->proposed_tp : null,
                $verification, $action->public_id,
            ),
            PositionManagementActionType::PartialClose => $this->requests->buildClosePosition(
                $position->symbol, $pid, $side,
                VolumeSafety::normalizeCloseVolume((float) $decision->proposed_close_volume, (float) $position->current_volume, $spec),
                $quote, $verification, $action->public_id, true,
            ),
            PositionManagementActionType::FullClose => $this->requests->buildClosePosition(
                $position->symbol, $pid, $side, (float) $position->current_volume,
                $quote, $verification, $action->public_id, false,
            ),
            PositionManagementActionType::CancelPending => $this->requests->buildCancelPending(
                (string) ($decision->payload['order_id'] ?? ''), $verification, $action->public_id,
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function dispatch(PositionManagementActionType $type, array $request, string $key, string $nonce, string $correlation): array
    {
        // Lowest adapter layer: DEMO check already in request.account.trade_mode
        if (($request['account']['trade_mode'] ?? null) !== 'DEMO') {
            throw ValidationException::withMessages(['trade_mode' => 'Lowest adapter DEMO check failed.']);
        }

        return match ($type) {
            PositionManagementActionType::ModifySl,
            PositionManagementActionType::ModifyTp,
            PositionManagementActionType::ModifySlTp => $this->bridge->modifyPositionProtection($request, $key, $nonce, $correlation),
            PositionManagementActionType::PartialClose => $this->bridge->partialClose($request, $key, $nonce, $correlation),
            PositionManagementActionType::FullClose => $this->bridge->closePosition($request, $key, $nonce, $correlation),
            PositionManagementActionType::CancelPending => $this->bridge->cancelPendingOrder($request, $key, $nonce, $correlation),
        };
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function applySuccess(
        ManagedPosition $position,
        TradeManagementDecision $decision,
        PositionManagementAction $action,
        array $response,
        bool $protective,
    ): void {
        $send = $response['send'] ?? [];
        $updates = [
            'last_managed_at' => now(),
            'last_synced_at' => now(),
        ];

        if (in_array($action->action_type, [
            PositionManagementActionType::ModifySl,
            PositionManagementActionType::ModifySlTp,
        ], true)) {
            $sl = $action->requested_sl ?? ($send['sl'] ?? null);
            if ($sl !== null) {
                $updates['current_stop_loss'] = $sl;
            }
            if ($decision->decision_type->value === 'MOVE_BREAK_EVEN') {
                $updates['break_even_applied'] = true;
                $updates['break_even_applied_at'] = now();
            }
            if ($decision->decision_type->value === 'TRAIL_STOP') {
                $updates['trailing_active'] = true;
                $updates['last_trail_stop'] = $sl;
                $mark = (float) (($response['mark'] ?? null) ?: ($position->entry_price));
                $updates['trailing_extreme'] = $mark;
            }
            $updates['management_status'] = ManagementStatus::Protecting;
        }

        if ($action->action_type === PositionManagementActionType::ModifyTp) {
            $updates['current_take_profit'] = $action->requested_tp;
        }

        if ($action->action_type === PositionManagementActionType::PartialClose) {
            $closed = (float) ($action->requested_close_volume ?? 0);
            $remain = round((float) $position->current_volume - $closed, 4);
            $updates['current_volume'] = max(0, $remain);
            $targetId = $decision->payload['target_id'] ?? null;
            if ($targetId) {
                $position->positionTargets()->whereKey($targetId)->update([
                    'status' => TargetHitStatus::Hit->value,
                    'hit_at' => now(),
                    'closed_volume' => $closed,
                ]);
            }
            if ($remain <= 0) {
                $updates['management_status'] = ManagementStatus::Closed;
                $updates['closed_at'] = now();
                $updates['close_reason'] = $decision->payload['close_reason'] ?? 'PARTIAL_THEN_FULL';
                $updates['current_volume'] = 0;
            }
        }

        if ($action->action_type === PositionManagementActionType::FullClose) {
            $updates['current_volume'] = 0;
            $updates['management_status'] = ManagementStatus::Closed;
            $updates['closed_at'] = now();
            $updates['close_reason'] = $decision->payload['close_reason'] ?? 'MANUAL';
        }

        $position->forceFill($updates)->save();
        $action->forceFill([
            'status' => PositionManagementActionStatus::Completed,
            'retcode' => $response['retcode'] ?? null,
            'broker_request_reference' => $send['order'] ?? null,
            'response_snapshot' => $response,
            'completed_at' => now(),
        ])->save();
        $decision->forceFill(['status' => ManagementDecisionStatus::Completed])->save();

        if ($position->management_status === ManagementStatus::Closed) {
            $this->summaries->finalize($position);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function event(
        ManagedPosition $position,
        TradeManagementDecision $decision,
        PositionManagementAction $action,
        string $type,
        array $payload,
    ): void {
        TradeManagementEvent::query()->create([
            'managed_position_id' => $position->id,
            'decision_id' => $decision->id,
            'action_id' => $action->id,
            'user_id' => $position->user_id,
            'environment' => 'DEMO',
            'event_type' => $type,
            'severity' => str_contains($type, 'UNKNOWN') || str_contains($type, 'REJECT') ? 'WARN' : 'INFO',
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
