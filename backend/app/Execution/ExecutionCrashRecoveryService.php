<?php

namespace App\Execution;

use App\Enums\ExecutionCommandStatus;
use App\Enums\ExecutionOutcome;
use App\Enums\ExecutionSubmissionState;
use App\Enums\TradingEnvironment;
use App\Models\ExecutionCommand;
use App\Models\ExecutionEvent;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crash / UNKNOWN recovery without blind retry of order_send.
 */
class ExecutionCrashRecoveryService
{
    public function __construct(
        private readonly ExecutionReconciliationService $reconciliation,
        private readonly AuditService $audit,
    ) {}

    public function recover(ExecutionCommand $command, Request $request): ExecutionCommand
    {
        abort_unless($command->user_id === $request->user()->id, 404);
        if ($command->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages(['command' => 'Recovery applies to DEMO commands only.']);
        }
        if ($command->submission_state !== ExecutionSubmissionState::Unknown->value) {
            throw ValidationException::withMessages(['command' => 'Only UNKNOWN DEMO submissions can be recovered.']);
        }
        if (! $command->blind_retry_forbidden) {
            throw ValidationException::withMessages(['command' => 'Safety invariant violated: blind retry must remain forbidden.']);
        }

        return DB::transaction(function () use ($command, $request): ExecutionCommand {
            $command = ExecutionCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();
            $run = $this->reconciliation->run($request->user(), $command->broker_account_id);

            // Recovery never re-invokes order_send. It only reconciles and marks reviewed.
            $command->forceFill([
                'submission_state' => ExecutionSubmissionState::Failed->value,
                'failure_message' => 'UNKNOWN recovered via reconciliation without blind retry.',
                'failed_at' => now(),
                'payload' => array_merge($command->payload ?? [], [
                    'recovery_run' => $run->public_id,
                    'blind_retry' => false,
                ]),
            ])->save();

            if ($command->status === ExecutionCommandStatus::Processing) {
                $command->transitionTo(ExecutionCommandStatus::Failed);
            }

            ExecutionEvent::query()->create([
                'execution_command_id' => $command->id,
                'trade_intent_id' => $command->trade_intent_id,
                'user_id' => $command->user_id,
                'environment' => TradingEnvironment::Demo,
                'event_type' => 'CRASH_RECOVERY',
                'severity' => 'WARNING',
                'payload' => [
                    'reconciliation_run' => $run->public_id,
                    'outcome' => ExecutionOutcome::TimeoutUnknown->value,
                    'order_send_retry' => false,
                ],
                'occurred_at' => now(),
            ]);
            $this->audit->record('execution.demo.recovered', $command, [], [
                'run' => $run->public_id,
                'blind_retry' => false,
            ], $request);

            return $command->fresh('executionResult');
        });
    }
}
