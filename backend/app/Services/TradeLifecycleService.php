<?php

namespace App\Services;

use App\Contracts\ExecutionAdapter;
use App\Enums\DealType;
use App\Enums\ExecutionCommandStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionFailureCode;
use App\Enums\OrderDirection;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PositionEventType;
use App\Enums\PositionStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\SignalDirection;
use App\Enums\SignalStatus;
use App\Enums\TradeIntentStatus;
use App\Enums\TradeOrigin;
use App\Enums\TradingEnvironment;
use App\Events\ExecutionCommandCreated;
use App\Events\OrderFilled;
use App\Events\PositionClosed;
use App\Events\PositionModified;
use App\Events\PositionOpened;
use App\Events\RiskDecisionRecorded;
use App\Events\TradeIntentCreated;
use App\Models\ExecutionCommand;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\SystemEvent;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TradeLifecycleService
{
    public function __construct(
        private readonly SimulationRiskEvaluator $riskEvaluator,
        private readonly ExecutionGate $gate,
        private readonly ExecutionAdapter $adapter,
        private readonly FinancialCalculator $calculator,
        private readonly AccountStateUpdater $accounts,
        private readonly AuditService $audit,
    ) {}

    /** @return array{intent:TradeIntent,replayed:bool} */
    public function createIntent(array $data, Request $request): array
    {
        return DB::transaction(function () use ($data, $request): array {
            $user = $request->user();
            $existing = TradeIntent::where('user_id', $user->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return ['intent' => $existing, 'replayed' => true];
            }

            $account = $user->brokerAccounts()->where('public_id', $data['account_public_id'])->firstOrFail();
            $instrument = TradingInstrument::where('public_id', $data['instrument_public_id'])->firstOrFail();
            $strategyId = null;
            if (! empty($data['strategy_id'])) {
                $strategyId = $user->strategies()->whereKey($data['strategy_id'])->value('id');
                abort_unless($strategyId, 404);
            }

            $intent = TradeIntent::create([
                'user_id' => $user->id,
                'broker_account_id' => $account->id,
                'trading_strategy_id' => $strategyId,
                'trading_instrument_id' => $instrument->id,
                'idempotency_key' => $data['idempotency_key'],
                'origin' => $data['origin'] ?? TradeOrigin::Manual,
                'side' => $data['side'],
                'order_type' => $data['order_type'],
                'volume' => $data['requested_volume'],
                'requested_volume' => $data['requested_volume'],
                'requested_price' => $data['requested_entry'] ?? null,
                'requested_entry' => $data['requested_entry'] ?? null,
                'stop_loss' => $data['stop_loss'] ?? null,
                'take_profit' => $data['take_profit'] ?? null,
                'take_profit_2' => $data['take_profit_2'] ?? null,
                'time_in_force' => $data['time_in_force'] ?? 'GTC',
                'comment' => $data['comment'] ?? null,
                'risk_percent' => $data['risk_percent'] ?? null,
                'created_by' => $user->id,
                'environment' => TradingEnvironment::Simulation,
                'status' => TradeIntentStatus::Draft,
                'metadata' => $data['metadata'] ?? null,
            ]);
            $intent->transitionTo(TradeIntentStatus::PendingRisk);
            $this->audit->record('trade_intent.created', $intent, [], $intent->toArray(), $request);
            TradeIntentCreated::dispatch($intent);

            return ['intent' => $intent, 'replayed' => false];
        });
    }

    /** @return array{intent:TradeIntent,replayed:bool} */
    public function createIntentFromSignal(Signal $signal, array $data, Request $request): array
    {
        abort_unless($signal->user_id === $request->user()->id, 404);
        if ($signal->environment !== TradingEnvironment::Simulation
            || ! in_array($signal->status, [SignalStatus::Generated, SignalStatus::Valid], true)
            || $signal->direction === SignalDirection::Neutral
            || ($signal->expires_at && $signal->expires_at->isPast())) {
            throw ValidationException::withMessages(['signal' => 'The signal is not eligible for simulation.']);
        }

        return DB::transaction(function () use ($signal, $data, $request): array {
            $signal = Signal::whereKey($signal->id)->lockForUpdate()->firstOrFail();
            if ($signal->intent()->exists()) {
                return ['intent' => $signal->intent()->firstOrFail(), 'replayed' => true];
            }

            $instrument = TradingInstrument::where('symbol', $signal->symbol)->firstOrFail();
            $account = $request->user()->brokerAccounts()->where('public_id', $data['account_public_id'])->firstOrFail();
            $intent = TradeIntent::create([
                'user_id' => $request->user()->id,
                'broker_account_id' => $account->id,
                'trading_strategy_id' => $signal->trading_strategy_id,
                'signal_id' => $signal->id,
                'trading_instrument_id' => $instrument->id,
                'idempotency_key' => $data['idempotency_key'],
                'origin' => TradeOrigin::Signal,
                'side' => $signal->direction->toOrderSide(),
                'order_type' => $data['order_type'] ?? OrderType::Market,
                'volume' => $data['requested_volume'],
                'requested_volume' => $data['requested_volume'],
                'requested_price' => $data['requested_entry'] ?? $signal->entry_reference,
                'requested_entry' => $data['requested_entry'] ?? $signal->entry_reference,
                'stop_loss' => $data['stop_loss'] ?? $signal->stop_loss,
                'take_profit' => $data['take_profit'] ?? $signal->take_profit_1_reference,
                'take_profit_2' => $data['take_profit_2'] ?? $signal->take_profit_2_reference,
                'time_in_force' => $data['time_in_force'] ?? 'GTC',
                'comment' => $data['comment'] ?? null,
                'risk_percent' => $data['risk_percent'] ?? null,
                'created_by' => $request->user()->id,
                'environment' => TradingEnvironment::Simulation,
                'status' => TradeIntentStatus::Draft,
            ]);
            $intent->transitionTo(TradeIntentStatus::PendingRisk);
            $signal->update(['status' => SignalStatus::Consumed, 'consumed_at' => now()]);
            $this->audit->record('trade_intent.created_from_signal', $intent, [], $intent->toArray(), $request);
            TradeIntentCreated::dispatch($intent);

            return ['intent' => $intent, 'replayed' => false];
        });
    }

    public function evaluate(TradeIntent $intent, Request $request): TradeIntent
    {
        $this->assertOwned($intent, $request->user());
        $decision = DB::transaction(fn () => $this->riskEvaluator->evaluate($intent));
        $this->audit->record('risk_decision.recorded', $decision, [], $decision->toArray(), $request);
        RiskDecisionRecorded::dispatch($decision);

        return $intent->fresh(['riskDecision']);
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function execute(TradeIntent $intent, string $idempotencyKey, Request $request): array
    {
        $this->assertOwned($intent, $request->user());
        $existing = ExecutionCommand::where('user_id', $request->user()->id)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return ['command' => $existing->load('order.position'), 'replayed' => true];
        }
        if ($intent->status !== TradeIntentStatus::RiskApproved
            || $intent->riskDecision?->status !== RiskDecisionStatus::Approved) {
            throw ValidationException::withMessages(['intent' => 'Only an approved intent can be executed.']);
        }

        $this->gate->assertCanExecute($intent->environment, $intent->brokerAccount);

        $result = DB::transaction(function () use ($intent, $idempotencyKey, $request): array {
            $intent = TradeIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $command = ExecutionCommand::create([
                'user_id' => $request->user()->id,
                'broker_account_id' => $intent->broker_account_id,
                'trade_intent_id' => $intent->id,
                'idempotency_key' => $idempotencyKey,
                'type' => ExecutionCommandType::PlaceOrder,
                'status' => ExecutionCommandStatus::Created,
                'environment' => TradingEnvironment::Simulation,
                'symbol' => $intent->instrument->symbol,
                'side' => $intent->side,
                'order_type' => $intent->order_type,
                'volume' => $intent->requested_volume,
                'price' => $intent->requested_entry,
                'stop_loss' => $intent->stop_loss,
                'take_profit' => $intent->take_profit,
                'expiration' => null,
                'requested_at' => now(),
            ]);
            $intent->transitionTo(TradeIntentStatus::CommandCreated);
            ExecutionCommandCreated::dispatch($command);

            try {
                $command->transitionTo(ExecutionCommandStatus::Queued);
                $command->transitionTo(ExecutionCommandStatus::Processing);
                $result = $this->adapter->execute($command);
                $order = $this->createOrder($command, $intent, $result);
                $command->update(['acknowledged_at' => now(), 'attempt_count' => 1]);
                $command->transitionTo(ExecutionCommandStatus::Acknowledged);
                $command->update(['completed_at' => now()]);
                $command->transitionTo(ExecutionCommandStatus::Completed);
                $this->audit->record('execution_command.completed', $command, [], $command->toArray(), $request);

                return ['command' => $command->fresh()->load('order.position'), 'error' => null];
            } catch (Throwable $exception) {
                $this->markFailed($command, $exception, $request);

                return ['command' => $command->fresh(), 'error' => 'Simulation execution failed safely.'];
            }
        });

        if ($result['error']) {
            throw ValidationException::withMessages(['execution' => $result['error']]);
        }

        return ['command' => $result['command'], 'replayed' => false];
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function close(Position $position, ?float $volume, string $idempotencyKey, Request $request): array
    {
        $this->assertPositionOwned($position, $request->user());
        $existing = ExecutionCommand::where('user_id', $request->user()->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return ['command' => $existing, 'replayed' => true];
        }

        $this->gate->assertCanExecute($position->environment, $position->brokerAccount);
        $closeVolume = $volume ?? (float) $position->current_volume;
        if ($position->status === PositionStatus::Closed || $closeVolume <= 0
            || $closeVolume > (float) $position->current_volume + 0.00000001
            || ! $this->calculator->isVolumeValid($position->instrument, $closeVolume)) {
            throw ValidationException::withMessages(['volume' => 'Close volume is invalid for this open position.']);
        }

        $command = DB::transaction(fn (): ExecutionCommand => $this->newPositionCommand(
            $position,
            abs($closeVolume - (float) $position->current_volume) < 0.00000001
                ? ExecutionCommandType::ClosePosition
                : ExecutionCommandType::PartialClose,
            $idempotencyKey,
            $request,
            ['volume' => $closeVolume],
        ));

        try {
            return DB::transaction(function () use ($position, $closeVolume, $command, $request): array {
                $position = Position::whereKey($position->id)->lockForUpdate()->firstOrFail();
                $isFullClose = abs($closeVolume - (float) $position->current_volume) < 0.00000001;
                $result = $this->adapter->execute($command);
                if (! $result['accepted'] || $result['fill_price'] === null) {
                    throw new DomainException($result['reason'] ?? 'Simulation adapter rejected the position command.');
                }
                $price = (float) $result['fill_price'];
                $realized = $this->calculator->profit($position->instrument, $position->direction, $closeVolume, (float) $position->average_entry_price, $price);
                $order = $this->createClosingOrder($command, $position, $closeVolume, $price);
                $before = (float) $position->current_volume;
                $after = round($before - $closeVolume, 4);
                $position->update([
                    'volume' => $after,
                    'current_volume' => $after,
                    'realized_pnl' => round((float) $position->realized_pnl + $realized, 4),
                    'current_price' => $price,
                    'margin_used' => $isFullClose ? 0 : round((float) $position->margin_used * ($after / $before), 4),
                    'closed_at' => $isFullClose ? now() : null,
                ]);
                $position->transitionTo($isFullClose ? PositionStatus::Closed : PositionStatus::PartiallyClosed);
                $position->deals()->create([
                    'order_id' => $order->id,
                    'broker_account_id' => $position->broker_account_id,
                    'execution_command_id' => $command->id,
                    'symbol' => $position->symbol,
                    'direction' => $position->direction === OrderDirection::Buy ? OrderDirection::Sell : OrderDirection::Buy,
                    'side' => $position->direction === OrderDirection::Buy ? OrderDirection::Sell : OrderDirection::Buy,
                    'volume' => $closeVolume,
                    'price' => $price,
                    'profit' => $realized,
                    'type' => $isFullClose ? DealType::Exit : DealType::PartialExit,
                    'origin' => TradeOrigin::Simulation,
                    'environment' => TradingEnvironment::Simulation,
                    'dealt_at' => now(),
                    'executed_at' => now(),
                ]);
                $position->events()->create([
                    'execution_command_id' => $command->id,
                    'type' => $isFullClose ? PositionEventType::Closed : PositionEventType::PartiallyClosed,
                    'origin' => TradeOrigin::Simulation,
                    'source' => 'SIMULATION',
                    'previous_state' => ['status' => $position->getOriginal('status'), 'current_volume' => $before],
                    'new_state' => ['status' => $isFullClose ? 'CLOSED' : 'PARTIALLY_CLOSED', 'current_volume' => $after],
                    'volume_before' => $before,
                    'volume_after' => $after,
                    'price' => $price,
                    'realized_pnl' => $realized,
                    'occurred_at' => now(),
                ]);
                $this->completeCommand($command);
                $this->accounts->capture($position->brokerAccount, $realized);
                $this->audit->record($isFullClose ? 'position.closed' : 'position.partially_closed', $position, [], $position->toArray(), $request);
                $isFullClose ? PositionClosed::dispatch($position) : PositionModified::dispatch($position);

                return ['command' => $command->fresh()->load('order'), 'replayed' => false];
            });
        } catch (Throwable $exception) {
            $this->markCommandFailed($command, $exception, $request);
            throw ValidationException::withMessages(['execution' => 'Simulation position execution failed safely.']);
        }
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function modifyStopLoss(Position $position, array $data, Request $request): array
    {
        return $this->modifyProtection($position, $data, $request, 'stop_loss');
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function modifyTakeProfit(Position $position, array $data, Request $request): array
    {
        return $this->modifyProtection($position, $data, $request, 'take_profit');
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function modifyProtection(Position $position, array $data, Request $request, ?string $field = null): array
    {
        $field ??= array_key_exists('stop_loss', $data) && ! array_key_exists('take_profit', $data)
            ? 'stop_loss'
            : (array_key_exists('take_profit', $data) && ! array_key_exists('stop_loss', $data) ? 'take_profit' : null);
        if ($field === null) {
            throw ValidationException::withMessages(['protection' => 'Modify stop loss and take profit with separate commands.']);
        }
        $this->assertPositionOwned($position, $request->user());
        $existing = ExecutionCommand::where('user_id', $request->user()->id)
            ->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ['command' => $existing, 'replayed' => true];
        }
        if ($position->status === PositionStatus::Closed) {
            throw ValidationException::withMessages(['position' => 'Closed positions cannot be modified.']);
        }
        $referencePrice = (float) ($position->current_price ?? $position->average_entry_price);
        $stopLoss = array_key_exists('stop_loss', $data) && $data['stop_loss'] !== null ? (float) $data['stop_loss'] : null;
        $takeProfit = array_key_exists('take_profit', $data) && $data['take_profit'] !== null ? (float) $data['take_profit'] : null;
        $invalidProtection = ($position->direction === OrderDirection::Buy
            && (($stopLoss !== null && $stopLoss >= $referencePrice) || ($takeProfit !== null && $takeProfit <= $referencePrice)))
            || ($position->direction === OrderDirection::Sell
                && (($stopLoss !== null && $stopLoss <= $referencePrice) || ($takeProfit !== null && $takeProfit >= $referencePrice)));
        if ($invalidProtection) {
            throw ValidationException::withMessages(['protection' => 'Protection values are on the invalid side of the current price.']);
        }
        $this->gate->assertCanExecute($position->environment, $position->brokerAccount);

        $command = DB::transaction(fn (): ExecutionCommand => $this->newPositionCommand(
            $position,
            $field === 'stop_loss'
                ? ExecutionCommandType::ModifyPositionStopLoss
                : ExecutionCommandType::ModifyPositionTakeProfit,
            $data['idempotency_key'],
            $request,
            $data,
        ));

        try {
            return DB::transaction(function () use ($position, $data, $request, $command, $field): array {
                $before = $position->only(['stop_loss', 'take_profit']);
                $result = $this->adapter->execute($command);
                if (! $result['accepted']) {
                    throw new DomainException($result['reason'] ?? 'Simulation adapter rejected the modification.');
                }
                $position->update([
                    'stop_loss' => array_key_exists('stop_loss', $data) ? $data['stop_loss'] : $position->stop_loss,
                    'take_profit' => array_key_exists('take_profit', $data) ? $data['take_profit'] : $position->take_profit,
                ]);
                $position->events()->create([
                    'execution_command_id' => $command->id,
                    'type' => $field === 'stop_loss'
                        ? PositionEventType::StopLossModified
                        : PositionEventType::TakeProfitModified,
                    'origin' => TradeOrigin::Simulation,
                    'source' => 'SIMULATION',
                    'previous_state' => $before,
                    'new_state' => $position->only(['stop_loss', 'take_profit']),
                    'volume_before' => $position->volume,
                    'volume_after' => $position->volume,
                    'changes' => ['before' => $before, 'after' => $position->only(['stop_loss', 'take_profit'])],
                    'occurred_at' => now(),
                ]);
                $this->completeCommand($command);
                $this->accounts->capture($position->brokerAccount);
                $this->audit->record('position.protection_modified', $position, $before, $position->toArray(), $request);
                PositionModified::dispatch($position);

                return ['command' => $command->fresh(), 'replayed' => false];
            });
        } catch (Throwable $exception) {
            $this->markCommandFailed($command, $exception, $request);
            throw ValidationException::withMessages(['execution' => 'Simulation position modification failed safely.']);
        }
    }

    /** @return array{command:ExecutionCommand,replayed:bool} */
    public function cancelOrder(Order $order, string $idempotencyKey, Request $request): array
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        if ($order->status !== OrderStatus::Accepted || $order->type === OrderType::Market) {
            throw ValidationException::withMessages(['order' => 'Only accepted pending simulation orders can be cancelled.']);
        }
        $this->gate->assertCanExecute($order->environment, $order->brokerAccount);
        $existing = ExecutionCommand::where('user_id', $request->user()->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return ['command' => $existing, 'replayed' => true];
        }

        $command = DB::transaction(function () use ($order, $idempotencyKey, $request): ExecutionCommand {
            $created = ExecutionCommand::create([
                'user_id' => $request->user()->id,
                'broker_account_id' => $order->broker_account_id,
                'trade_intent_id' => $order->executionCommand?->trade_intent_id,
                'idempotency_key' => $idempotencyKey,
                'type' => ExecutionCommandType::CancelOrder,
                'status' => ExecutionCommandStatus::Created,
                'environment' => TradingEnvironment::Simulation,
                'symbol' => $order->symbol,
                'side' => $order->side,
                'order_type' => $order->order_type,
                'volume' => $order->remaining_volume,
                'price' => $order->requested_price,
                'requested_at' => now(),
                'payload' => ['order_public_id' => $order->public_id],
            ]);
            ExecutionCommandCreated::dispatch($created);
            $created->transitionTo(ExecutionCommandStatus::Queued);
            $created->transitionTo(ExecutionCommandStatus::Processing);

            return $created;
        });

        try {
            return DB::transaction(function () use ($order, $command, $request): array {
                $result = $this->adapter->execute($command);
                if (! $result['accepted']) {
                    throw new DomainException($result['reason'] ?? 'Simulation adapter rejected the cancellation.');
                }
                $order->transitionTo(OrderStatus::CancelPending);
                $order->update(['cancelled_at' => now()]);
                $order->transitionTo(OrderStatus::Cancelled);
                $this->completeCommand($command);
                $this->audit->record('order.cancelled', $order, [], $order->toArray(), $request);

                return ['command' => $command, 'replayed' => false];
            });
        } catch (Throwable $exception) {
            $this->markCommandFailed($command, $exception, $request);
            throw ValidationException::withMessages(['execution' => 'Simulation order cancellation failed safely.']);
        }
    }

    /** @param array{accepted:bool,fill_price:?string,filled_at:?string,reason:?string} $result */
    private function createOrder(ExecutionCommand $command, TradeIntent $intent, array $result): Order
    {
        if (! $result['accepted']) {
            throw new DomainException($result['reason'] ?? 'Simulation adapter rejected the order.');
        }

        $order = Order::create([
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
            'volume' => $intent->requested_volume,
            'requested_volume' => $intent->requested_volume,
            'remaining_volume' => $intent->requested_volume,
            'requested_price' => $intent->requested_entry,
            'risk_amount' => $intent->riskDecision->risk_amount,
            'risk_percent' => $intent->risk_percent,
            'stop_loss' => $intent->stop_loss,
            'take_profit' => $intent->take_profit,
            'status' => OrderStatus::Created,
            'environment' => TradingEnvironment::Simulation,
            'simulated' => true,
            'broker_transmitted' => false,
            'metadata' => ['time_in_force' => $intent->time_in_force->value, 'comment' => $intent->comment],
            'requested_at' => now(),
        ]);
        $order->transitionTo(OrderStatus::Submitted);
        $order->update(['submitted_at' => now()]);
        $order->transitionTo(OrderStatus::Accepted);
        $order->update(['accepted_at' => now()]);

        if ($intent->order_type->isPending()) {
            return $order;
        }

        $price = (float) $result['fill_price'];
        $order->update([
            'filled_volume' => $intent->requested_volume,
            'remaining_volume' => 0,
            'fill_price' => $price,
            'average_fill_price' => $price,
            'filled_at' => $result['filled_at'],
        ]);
        $order->transitionTo(OrderStatus::Filled);
        $position = Position::create([
            'broker_account_id' => $intent->broker_account_id,
            'opening_order_id' => $order->id,
            'user_id' => $intent->user_id,
            'trading_instrument_id' => $intent->trading_instrument_id,
            'trading_strategy_id' => $intent->trading_strategy_id,
            'signal_id' => $intent->signal_id,
            'symbol' => $intent->instrument->symbol,
            'direction' => $intent->side,
            'side' => $intent->side,
            'volume' => $intent->requested_volume,
            'initial_volume' => $intent->requested_volume,
            'current_volume' => $intent->requested_volume,
            'open_price' => $price,
            'average_entry_price' => $price,
            'current_price' => $price,
            'stop_loss' => $intent->stop_loss,
            'take_profit' => $intent->take_profit,
            'margin_used' => $this->calculator->margin(
                $intent->instrument,
                (float) $intent->requested_volume,
                $price,
                $intent->brokerAccount->leverage,
            ),
            'status' => PositionStatus::Open,
            'environment' => TradingEnvironment::Simulation,
            'opened_at' => now(),
        ]);
        $position->deals()->create([
            'order_id' => $order->id,
            'broker_account_id' => $intent->broker_account_id,
            'execution_command_id' => $command->id,
            'symbol' => $position->symbol,
            'direction' => $position->direction,
            'side' => $position->side,
            'volume' => $position->volume,
            'price' => $price,
            'type' => DealType::Entry,
            'origin' => TradeOrigin::Simulation,
            'environment' => TradingEnvironment::Simulation,
            'dealt_at' => now(),
            'executed_at' => now(),
        ]);
        $position->events()->create([
            'execution_command_id' => $command->id,
            'type' => PositionEventType::Opened,
            'origin' => TradeOrigin::Simulation,
            'source' => 'SIMULATION',
            'previous_state' => null,
            'new_state' => ['status' => 'OPEN', 'current_volume' => $position->current_volume],
            'volume_after' => $position->volume,
            'price' => $price,
            'occurred_at' => now(),
        ]);
        $this->accounts->capture($position->brokerAccount);
        OrderFilled::dispatch($order);
        PositionOpened::dispatch($position);

        return $order;
    }

    private function createClosingOrder(ExecutionCommand $command, Position $position, float $volume, float $price): Order
    {
        $order = Order::create([
            'command_id' => $command->public_id,
            'execution_command_id' => $command->id,
            'user_id' => $command->user_id,
            'broker_account_id' => $position->broker_account_id,
            'trading_instrument_id' => $position->trading_instrument_id,
            'trading_strategy_id' => $position->trading_strategy_id,
            'idempotency_key' => $command->idempotency_key,
            'symbol' => $position->symbol,
            'direction' => $position->direction === OrderDirection::Buy ? OrderDirection::Sell : OrderDirection::Buy,
            'side' => $position->direction === OrderDirection::Buy ? OrderDirection::Sell : OrderDirection::Buy,
            'type' => OrderType::Market,
            'order_type' => OrderType::Market,
            'volume' => $volume,
            'requested_volume' => $volume,
            'filled_volume' => $volume,
            'remaining_volume' => 0,
            'fill_price' => $price,
            'average_fill_price' => $price,
            'status' => OrderStatus::Created,
            'environment' => TradingEnvironment::Simulation,
            'simulated' => true,
            'broker_transmitted' => false,
            'requested_at' => now(),
        ]);
        $order->transitionTo(OrderStatus::Submitted);
        $order->update(['submitted_at' => now()]);
        $order->transitionTo(OrderStatus::Accepted);
        $order->update(['accepted_at' => now()]);
        $order->transitionTo(OrderStatus::Filled);
        $order->update(['filled_at' => now()]);
        OrderFilled::dispatch($order);

        return $order;
    }

    private function newPositionCommand(
        Position $position,
        ExecutionCommandType $type,
        string $idempotencyKey,
        Request $request,
        array $payload,
    ): ExecutionCommand {
        $command = ExecutionCommand::create([
            'user_id' => $request->user()->id,
            'broker_account_id' => $position->broker_account_id,
            'position_id' => $position->id,
            'idempotency_key' => $idempotencyKey,
            'type' => $type,
            'status' => ExecutionCommandStatus::Created,
            'environment' => TradingEnvironment::Simulation,
            'symbol' => $position->symbol,
            'side' => $position->direction,
            'order_type' => OrderType::Market,
            'volume' => $payload['volume'] ?? null,
            'price' => $position->current_price,
            'stop_loss' => $payload['stop_loss'] ?? null,
            'take_profit' => $payload['take_profit'] ?? null,
            'payload' => $payload,
            'requested_at' => now(),
        ]);
        ExecutionCommandCreated::dispatch($command);
        $command->transitionTo(ExecutionCommandStatus::Queued);
        $command->transitionTo(ExecutionCommandStatus::Processing);

        return $command;
    }

    private function markFailed(ExecutionCommand $command, Throwable $exception, Request $request): void
    {
        $this->markCommandFailed($command, $exception, $request);
    }

    private function markCommandFailed(ExecutionCommand $command, Throwable $exception, Request $request): void
    {
        $safeError = str($exception->getMessage())->limit(200)->toString();
        $command->update([
            'failure_code' => ExecutionFailureCode::SimulationExecutionFailed,
            'safe_error' => $safeError,
            'error_code' => ExecutionFailureCode::SimulationExecutionFailed,
            'error_message' => $safeError,
            'attempt_count' => max(1, $command->attempt_count),
            'failed_at' => now(),
        ]);
        $command->transitionTo(ExecutionCommandStatus::Failed);
        SystemEvent::create([
            'level' => 'ERROR',
            'category' => 'SIMULATION_EXECUTION',
            'message' => 'A simulation execution command failed safely.',
            'context' => ['command_public_id' => $command->public_id, 'failure_code' => $command->failure_code],
            'occurred_at' => now(),
        ]);
        $this->audit->record('execution_command.failed', $command, [], [
            'status' => $command->status,
            'failure_code' => $command->failure_code,
        ], $request, 'Simulation execution failed safely.', 'FAILED');
    }

    private function completeCommand(ExecutionCommand $command): void
    {
        $command->update(['acknowledged_at' => now(), 'attempt_count' => max(1, $command->attempt_count)]);
        $command->transitionTo(ExecutionCommandStatus::Acknowledged);
        $command->update(['completed_at' => now()]);
        $command->transitionTo(ExecutionCommandStatus::Completed);
    }

    private function assertOwned(TradeIntent $intent, User $user): void
    {
        abort_unless($intent->user_id === $user->id, 404);
    }

    private function assertPositionOwned(Position $position, User $user): void
    {
        abort_unless($position->user_id === $user->id, 404);
    }
}
