<?php

namespace App\Execution;

use App\Contracts\DemoBridgeClient;
use App\Enums\DealType;
use App\Enums\ExecutionCommandStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionFailureCode;
use App\Enums\ExecutionOutcome;
use App\Enums\ExecutionSubmissionState;
use App\Enums\OrderStatus;
use App\Enums\PositionEventType;
use App\Enums\PositionStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskReservationStatus;
use App\Enums\TradeIntentStatus;
use App\Enums\TradeOrigin;
use App\Enums\TradingEnvironment;
use App\Models\ExecutionCommand;
use App\Models\ExecutionConfirmation;
use App\Models\ExecutionEvent;
use App\Models\ExecutionResult;
use App\Models\Order;
use App\Models\Position;
use App\Models\RiskReservation;
use App\Models\ServiceHeartbeat;
use App\Models\TradeIntent;
use App\Services\AccountStateUpdater;
use App\Services\AuditService;
use App\Services\ExecutionGate;
use App\Services\FinancialCalculator;
use App\Services\RiskReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DEMO-only ExecutionEngine. SIMULATION remains on TradeLifecycleService + SimulationExecutionAdapter.
 */
class ExecutionEngineService
{
    public function __construct(
        private readonly ExecutionGate $gate,
        private readonly DemoAccountVerifier $verifier,
        private readonly ExecutionConfirmationService $confirmations,
        private readonly ExecutionSubmissionLockService $locks,
        private readonly ExecutionPriceGate $priceGate,
        private readonly Mt5OrderRequestBuilder $requestBuilder,
        private readonly Mt5RetcodeMapper $retcodes,
        private readonly DemoBridgeClient $bridge,
        private readonly RiskReservationService $reservations,
        private readonly FinancialCalculator $calculator,
        private readonly AccountStateUpdater $accounts,
        private readonly AuditService $audit,
        private readonly ExecutionReconciliationService $reconciliation,
        private readonly ExecutionCrashRecoveryService $recovery,
    ) {}

    /**
     * @return array{command:ExecutionCommand,result:?ExecutionResult,replayed:bool}
     */
    public function submitDemo(
        TradeIntent $intent,
        ExecutionConfirmation $confirmation,
        string $confirmToken,
        string $idempotencyKey,
        Request $request,
    ): array {
        abort_unless($intent->user_id === $request->user()->id, 404);
        abort_unless($confirmation->trade_intent_id === $intent->id, 404);

        $existing = ExecutionCommand::query()
            ->where('user_id', $request->user()->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return [
                'command' => $existing->load(['order.position', 'executionResult']),
                'result' => $existing->executionResult,
                'replayed' => true,
            ];
        }

        if ($intent->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages(['intent' => 'ExecutionEngine DEMO submit requires a DEMO intent.']);
        }
        if ($intent->status !== TradeIntentStatus::RiskApproved
            || $intent->riskDecision?->status !== RiskDecisionStatus::Approved) {
            throw ValidationException::withMessages(['intent' => 'Only risk-approved DEMO intents can be submitted.']);
        }

        $this->confirmations->assertConsumable($confirmation, $confirmToken);
        $this->gate->assertCanExecute(TradingEnvironment::Demo, $intent->brokerAccount);

        $lock = $this->locks->acquire($request->user(), $intent);

        try {
            $outcome = DB::transaction(function () use ($intent, $confirmation, $idempotencyKey, $request, $lock): array {
                $intent = TradeIntent::query()->whereKey($intent->id)->with(['instrument', 'brokerAccount', 'riskDecision.reservation'])->lockForUpdate()->firstOrFail();

                $verification = $this->verifier->verify($intent->brokerAccount, true);
                $quote = $this->bridge->freshQuote($intent->instrument->symbol);
                $spec = $this->bridge->freshSymbolSpec($intent->instrument->symbol);
                $source = strtoupper((string) ($quote['source'] ?? ''));
                if ($source === 'MOCK' || str_contains($source, 'SIMULATION')) {
                    throw ValidationException::withMessages([
                        'quote' => 'Refusing silent mock/simulation prices under MT5 DEMO label.',
                    ]);
                }

                $volume = (float) ($intent->riskDecision->approved_volume ?? $intent->requested_volume);
                $this->priceGate->assertPlaceOrder($intent, $quote, $spec, $volume);

                $decision = $intent->riskDecision;
                if ($decision === null || $decision->status !== RiskDecisionStatus::Approved) {
                    throw ValidationException::withMessages(['risk' => 'Risk revalidation failed; decision not approved.']);
                }

                /** @var RiskReservation|null $reservation */
                $reservation = $decision->reservation;
                if ($reservation && $reservation->status === RiskReservationStatus::Active && $reservation->expires_at?->isPast()) {
                    $this->reservations->expireDue();
                    throw ValidationException::withMessages(['reservation' => 'Risk reservation expired; fail closed.']);
                }

                $command = ExecutionCommand::query()->create([
                    'user_id' => $request->user()->id,
                    'broker_account_id' => $intent->broker_account_id,
                    'trade_intent_id' => $intent->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => ExecutionCommandType::PlaceOrder,
                    'status' => ExecutionCommandStatus::Created,
                    'environment' => TradingEnvironment::Demo,
                    'symbol' => $intent->instrument->symbol,
                    'side' => $intent->side,
                    'order_type' => $intent->order_type,
                    'volume' => $volume,
                    'price' => $quote['ask'] ?? null,
                    'stop_loss' => $intent->stop_loss,
                    'take_profit' => $intent->take_profit,
                    'requested_at' => now(),
                    'attempt_count' => 1,
                    'submission_state' => ExecutionSubmissionState::Locked->value,
                    'blind_retry_forbidden' => true,
                    'payload' => [
                        'confirmation_public_id' => $confirmation->public_id,
                        'auto_demo' => false,
                        'verification' => $verification,
                    ],
                ]);
                $command->transitionTo(ExecutionCommandStatus::Queued);
                $command->transitionTo(ExecutionCommandStatus::Processing);
                $lock->update(['execution_command_id' => $command->id]);

                $requestPayload = $this->requestBuilder->buildPlaceOrder($intent, $command, $quote, $spec, $verification);
                $correlationId = (string) Str::uuid();
                $nonce = Str::random(32);
                $command->forceFill([
                    'submission_state' => ExecutionSubmissionState::OrderCheck->value,
                    'bridge_correlation_id' => $correlationId,
                    'bridge_nonce' => $nonce,
                ])->save();

                $this->recordEvent($command, 'ORDER_CHECK_STARTED', ['request' => $requestPayload]);

                $bridgeResult = $this->bridge->checkAndSend($requestPayload, $idempotencyKey, $nonce, $correlationId);

                $checkCode = (string) ($bridgeResult['check']['retcode'] ?? '');
                $command->forceFill([
                    'order_check_passed' => in_array($checkCode, ['0', '10009'], true),
                    'submitted_at' => now(),
                    'submission_state' => ExecutionSubmissionState::Submitting->value,
                ])->save();

                if (($bridgeResult['send'] ?? null) === null && ($bridgeResult['outcome'] ?? '') === 'TIMEOUT_UNKNOWN') {
                    return $this->finalizeUnknown($command, $intent, $requestPayload, $bridgeResult, $request);
                }

                $mapped = $this->retcodes->map(isset($bridgeResult['retcode']) ? (string) $bridgeResult['retcode'] : null);
                if (in_array($mapped['outcome'], [ExecutionOutcome::Rejected, ExecutionOutcome::Failed], true)) {
                    return $this->finalizeRejected($command, $intent, $requestPayload, $bridgeResult, $mapped, $request, $reservation);
                }

                return $this->finalizeFilled(
                    $command,
                    $intent,
                    $requestPayload,
                    $bridgeResult,
                    $mapped,
                    $quote,
                    $request,
                    $reservation,
                    $confirmation,
                );
            });

            return $outcome;
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $this->locks->release($lock);
            $this->pulseHeartbeat();
        }
    }

    public function recoverUnknown(ExecutionCommand $command, Request $request): ExecutionCommand
    {
        return $this->recovery->recover($command, $request);
    }

    public function reconcile(Request $request, ?int $accountId = null): \App\Models\ExecutionReconciliationRun
    {
        return $this->reconciliation->run($request->user(), $accountId);
    }

    /**
     * @param  array<string,mixed>  $requestPayload
     * @param  array<string,mixed>  $bridgeResult
     * @param  array{class:string,outcome:ExecutionOutcome,retryable:bool,blind_retry:bool}  $mapped
     * @param  array<string,mixed>  $quote
     * @return array{command:ExecutionCommand,result:ExecutionResult,replayed:bool}
     */
    private function finalizeFilled(
        ExecutionCommand $command,
        TradeIntent $intent,
        array $requestPayload,
        array $bridgeResult,
        array $mapped,
        array $quote,
        Request $request,
        ?RiskReservation $reservation,
        ExecutionConfirmation $confirmation,
    ): array {
        $send = $bridgeResult['send'] ?? [];
        $filledVolume = (float) ($send['volume'] ?? $command->volume);
        $fillPrice = (float) ($send['price'] ?? $quote['ask']);
        $partial = $mapped['outcome'] === ExecutionOutcome::PartiallyFilled
            || $filledVolume + 1e-8 < (float) $command->volume;

        $command->forceFill([
            'submission_state' => ExecutionSubmissionState::Completed->value,
            'acknowledged_at' => now(),
            'completed_at' => now(),
        ])->save();
        $command->transitionTo(ExecutionCommandStatus::Acknowledged);
        $command->transitionTo(ExecutionCommandStatus::Completed);

        $order = Order::query()->create([
            'command_id' => $command->public_id,
            'execution_command_id' => $command->id,
            'trade_intent_id' => $intent->id,
            'user_id' => $intent->user_id,
            'broker_account_id' => $intent->broker_account_id,
            'signal_id' => $intent->signal_id,
            'trading_instrument_id' => $intent->trading_instrument_id,
            'trading_strategy_id' => $intent->trading_strategy_id,
            'idempotency_key' => $command->idempotency_key,
            'symbol' => $intent->instrument->symbol,
            'direction' => $intent->side,
            'side' => $intent->side,
            'type' => $intent->order_type,
            'order_type' => $intent->order_type,
            'volume' => $command->volume,
            'requested_volume' => $command->volume,
            'remaining_volume' => max(0, (float) $command->volume - $filledVolume),
            'filled_volume' => $filledVolume,
            'fill_price' => $fillPrice,
            'average_fill_price' => $fillPrice,
            'requested_price' => $intent->requested_entry,
            'risk_amount' => $intent->riskDecision->risk_amount,
            'risk_percent' => $intent->risk_percent,
            'stop_loss' => $intent->stop_loss,
            'take_profit' => $intent->take_profit,
            'status' => OrderStatus::Created,
            'environment' => TradingEnvironment::Demo,
            'simulated' => false,
            'broker_transmitted' => true,
            'external_order_id' => $send['order'] ?? null,
            'metadata' => [
                'deal' => $send['deal'] ?? null,
                'position_id' => $bridgeResult['position_id'] ?? null,
                'hedging' => $bridgeResult['hedging'] ?? true,
                'netting' => $bridgeResult['netting'] ?? false,
                'source' => 'MT5_DEMO',
                'filling_mode' => $partial ? 'PARTIAL' : 'FULL',
            ],
            'requested_at' => now(),
        ]);
        $order->transitionTo(OrderStatus::Submitted);
        $order->update(['submitted_at' => now()]);
        $order->transitionTo(OrderStatus::Accepted);
        $order->update(['accepted_at' => now()]);
        $order->transitionTo($partial ? OrderStatus::PartiallyFilled : OrderStatus::Filled);
        $order->update(['filled_at' => now()]);

        $margin = $this->calculator->margin(
            $intent->instrument,
            $filledVolume,
            $fillPrice,
            (int) ($intent->brokerAccount->leverage ?? 100),
        );

        $position = Position::query()->create([
            'broker_account_id' => $intent->broker_account_id,
            'opening_order_id' => $order->id,
            'user_id' => $intent->user_id,
            'trading_instrument_id' => $intent->trading_instrument_id,
            'trading_strategy_id' => $intent->trading_strategy_id,
            'signal_id' => $intent->signal_id,
            'symbol' => $intent->instrument->symbol,
            'direction' => $intent->side,
            'side' => $intent->side,
            'volume' => $filledVolume,
            'initial_volume' => $filledVolume,
            'current_volume' => $filledVolume,
            'open_price' => $fillPrice,
            'average_entry_price' => $fillPrice,
            'current_price' => $fillPrice,
            'stop_loss' => $intent->stop_loss,
            'take_profit' => $intent->take_profit,
            'floating_pnl' => 0,
            'unrealized_pnl' => 0,
            'margin_used' => $margin,
            'status' => PositionStatus::Open,
            'environment' => TradingEnvironment::Demo,
            'external_position_id' => $bridgeResult['position_id'] ?? null,
            'opened_at' => now(),
            'metadata' => [
                'filling_mode' => $partial ? 'PARTIAL' : 'FULL',
                'account_mode' => ($bridgeResult['hedging'] ?? true) ? 'HEDGING' : 'NETTING',
            ],
        ]);

        $position->deals()->create([
            'order_id' => $order->id,
            'broker_account_id' => $intent->broker_account_id,
            'execution_command_id' => $command->id,
            'symbol' => $position->symbol,
            'direction' => $position->direction,
            'side' => $position->side,
            'volume' => $filledVolume,
            'price' => $fillPrice,
            'type' => DealType::Entry,
            'origin' => TradeOrigin::Demo,
            'environment' => TradingEnvironment::Demo,
            'external_deal_id' => $send['deal'] ?? null,
            'dealt_at' => now(),
            'executed_at' => now(),
        ]);
        $position->events()->create([
            'execution_command_id' => $command->id,
            'type' => PositionEventType::Opened,
            'origin' => TradeOrigin::Demo,
            'source' => 'MT5_DEMO',
            'previous_state' => null,
            'new_state' => ['status' => 'OPEN', 'current_volume' => $position->current_volume],
            'volume_after' => $position->volume,
            'price' => $fillPrice,
            'occurred_at' => now(),
        ]);

        $intent->transitionTo(TradeIntentStatus::CommandCreated);
        $this->confirmations->consume($confirmation);
        if ($reservation && $reservation->status === RiskReservationStatus::Active) {
            $this->reservations->consume($reservation);
        }
        $this->accounts->capture($intent->brokerAccount);

        $result = ExecutionResult::query()->create([
            'execution_command_id' => $command->id,
            'trade_intent_id' => $intent->id,
            'user_id' => $intent->user_id,
            'environment' => TradingEnvironment::Demo,
            'outcome' => $partial ? ExecutionOutcome::PartiallyFilled : ExecutionOutcome::Filled,
            'retcode' => (string) ($bridgeResult['retcode'] ?? ''),
            'retcode_class' => $mapped['class'],
            'broker_order_id' => $send['order'] ?? null,
            'broker_deal_id' => $send['deal'] ?? null,
            'broker_position_id' => $bridgeResult['position_id'] ?? null,
            'requested_volume' => $command->volume,
            'filled_volume' => $filledVolume,
            'fill_price' => $fillPrice,
            'partial_fill' => $partial,
            'filling_mode' => $partial ? 'PARTIAL' : 'FULL',
            'request_snapshot' => $requestPayload,
            'response_snapshot' => $bridgeResult,
            'verification_snapshot' => $command->payload['verification'] ?? null,
            'recorded_at' => now(),
        ]);

        $this->recordEvent($command, 'DEMO_SUBMIT_FILLED', [
            'order' => $order->public_id,
            'position' => $position->public_id,
            'partial' => $partial,
        ]);
        $this->audit->record('execution.demo.filled', $command, [], [
            'result' => $result->public_id,
            'environment' => 'DEMO',
        ], $request);

        return ['command' => $command->fresh(['order.position', 'executionResult']), 'result' => $result, 'replayed' => false];
    }

    /**
     * @param  array{class:string,outcome:ExecutionOutcome,retryable:bool,blind_retry:bool}  $mapped
     * @return array{command:ExecutionCommand,result:ExecutionResult,replayed:bool}
     */
    private function finalizeRejected(
        ExecutionCommand $command,
        TradeIntent $intent,
        array $requestPayload,
        array $bridgeResult,
        array $mapped,
        Request $request,
        ?RiskReservation $reservation,
    ): array {
        $command->forceFill([
            'submission_state' => ExecutionSubmissionState::Rejected->value,
                        'failure_code' => ExecutionFailureCode::AdapterRejected,
                        'safe_error' => 'DEMO bridge rejected the order.',
                        'error_message' => 'DEMO bridge rejected the order.',
                        'failed_at' => now(),
            'payload' => array_merge($command->payload ?? [], ['bridge' => $bridgeResult]),
        ])->save();
        $command->transitionTo(ExecutionCommandStatus::Failed);
        if ($reservation && $reservation->status === RiskReservationStatus::Active) {
            $this->reservations->release($reservation);
        }

        $result = ExecutionResult::query()->create([
            'execution_command_id' => $command->id,
            'trade_intent_id' => $intent->id,
            'user_id' => $intent->user_id,
            'environment' => TradingEnvironment::Demo,
            'outcome' => $mapped['outcome'],
            'retcode' => (string) ($bridgeResult['retcode'] ?? ''),
            'retcode_class' => $mapped['class'],
            'requested_volume' => $command->volume,
            'request_snapshot' => $requestPayload,
            'response_snapshot' => $bridgeResult,
            'verification_snapshot' => $command->payload['verification'] ?? null,
            'recorded_at' => now(),
        ]);
        $this->recordEvent($command, 'DEMO_SUBMIT_REJECTED', ['retcode' => $bridgeResult['retcode'] ?? null]);
        $this->audit->record('execution.demo.rejected', $command, [], ['result' => $result->public_id], $request);

        return ['command' => $command->fresh('executionResult'), 'result' => $result, 'replayed' => false];
    }

    /**
     * @return array{command:ExecutionCommand,result:ExecutionResult,replayed:bool}
     */
    private function finalizeUnknown(
        ExecutionCommand $command,
        TradeIntent $intent,
        array $requestPayload,
        array $bridgeResult,
        Request $request,
    ): array {
        $command->forceFill([
            'submission_state' => ExecutionSubmissionState::Unknown->value,
            'unknown_reason' => 'BRIDGE_TIMEOUT',
            'blind_retry_forbidden' => true,
            'failure_code' => ExecutionFailureCode::AdapterTimeout,
            'safe_error' => 'DEMO submit timed out; status UNKNOWN — no blind retry.',
            'error_message' => 'DEMO submit timed out; status UNKNOWN — no blind retry.',
            'payload' => array_merge($command->payload ?? [], ['bridge' => $bridgeResult]),
        ])->save();

        $this->recordEvent($command, 'DEMO_SUBMIT_UNKNOWN', [
            'blind_retry_forbidden' => true,
            'recovery' => 'reconcile_required',
        ], 'WARNING');

        $result = ExecutionResult::query()->create([
            'execution_command_id' => $command->id,
            'trade_intent_id' => $intent->id,
            'user_id' => $intent->user_id,
            'environment' => TradingEnvironment::Demo,
            'outcome' => ExecutionOutcome::TimeoutUnknown,
            'retcode' => (string) ($bridgeResult['retcode'] ?? '10012'),
            'retcode_class' => 'UNKNOWN',
            'requested_volume' => $command->volume,
            'request_snapshot' => $requestPayload,
            'response_snapshot' => $bridgeResult,
            'verification_snapshot' => $command->payload['verification'] ?? null,
            'recorded_at' => now(),
        ]);
        $this->audit->record('execution.demo.unknown', $command, [], [
            'result' => $result->public_id,
            'blind_retry' => false,
        ], $request);

        return ['command' => $command->fresh('executionResult'), 'result' => $result, 'replayed' => false];
    }

    private function recordEvent(ExecutionCommand $command, string $type, array $payload, string $severity = 'INFO'): void
    {
        ExecutionEvent::query()->create([
            'execution_command_id' => $command->id,
            'trade_intent_id' => $command->trade_intent_id,
            'user_id' => $command->user_id,
            'environment' => TradingEnvironment::Demo,
            'event_type' => $type,
            'severity' => $severity,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function pulseHeartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'EXECUTION_ENGINE',
            'instance_id' => 'phase-10-execution-engine',
            'status' => 'ONLINE',
            'environment' => TradingEnvironment::Demo->value,
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => [
                'phase' => 10,
                'auto_demo' => false,
                'live_execution' => false,
                'order_send' => 'AUTHORIZED_DEMO_PATH_ONLY',
            ],
            'metadata' => ['phase' => 10],
        ]);
    }
}
