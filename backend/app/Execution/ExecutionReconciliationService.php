<?php

namespace App\Execution;

use App\Contracts\DemoBridgeClient;
use App\Enums\ExecutionOutcome;
use App\Enums\ExecutionSubmissionState;
use App\Enums\TradingEnvironment;
use App\Models\ExecutionCommand;
use App\Models\ExecutionEvent;
use App\Models\ExecutionReconciliationRun;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class ExecutionReconciliationService
{
    public function __construct(
        private readonly DemoBridgeClient $bridge,
        private readonly AuditService $audit,
    ) {}

    public function run(User $user, ?int $brokerAccountId = null): ExecutionReconciliationRun
    {
        return DB::transaction(function () use ($user, $brokerAccountId): ExecutionReconciliationRun {
            $run = ExecutionReconciliationRun::query()->create([
                'user_id' => $user->id,
                'broker_account_id' => $brokerAccountId,
                'environment' => TradingEnvironment::Demo,
                'status' => 'RUNNING',
                'started_at' => now(),
            ]);

            $orders = $this->bridge->syncOrders();
            $deals = $this->bridge->syncDeals();
            $positions = $this->bridge->syncPositions();

            $unknownCommands = ExecutionCommand::query()
                ->where('user_id', $user->id)
                ->where('environment', TradingEnvironment::Demo)
                ->where('submission_state', ExecutionSubmissionState::Unknown->value)
                ->when($brokerAccountId, fn ($q) => $q->where('broker_account_id', $brokerAccountId))
                ->get();

            $mismatches = 0;
            foreach ($unknownCommands as $command) {
                $matched = collect($orders)->first(function (array $order) use ($command): bool {
                    return ($order['symbol'] ?? null) === $command->symbol;
                });
                ExecutionEvent::query()->create([
                    'execution_command_id' => $command->id,
                    'trade_intent_id' => $command->trade_intent_id,
                    'user_id' => $user->id,
                    'environment' => TradingEnvironment::Demo,
                    'event_type' => $matched ? 'RECONCILE_MATCH' : 'RECONCILE_MISMATCH',
                    'severity' => $matched ? 'INFO' : 'WARNING',
                    'payload' => [
                        'run' => $run->public_id,
                        'matched' => (bool) $matched,
                        'blind_retry' => false,
                    ],
                    'occurred_at' => now(),
                ]);
                if (! $matched) {
                    $mismatches++;
                }
            }

            $run->update([
                'status' => 'COMPLETED',
                'orders_synced' => count($orders),
                'deals_synced' => count($deals),
                'positions_synced' => count($positions),
                'mismatches' => $mismatches,
                'summary' => [
                    'unknown_commands' => $unknownCommands->count(),
                    'netting_hedging' => 'BOTH_SUPPORTED',
                    'source' => 'DEMO_BRIDGE_SYNC',
                ],
                'finished_at' => now(),
            ]);

            return $run->fresh();
        });
    }
}
