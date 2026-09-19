<?php

namespace App\Automation;

use App\Enums\AutomationQueueName;
use App\Models\AutomationQueueJob;
use App\Models\AutomationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Modular priority queues. SAFETY always preempts INTELLIGENCE/AI.
 */
class AutomationJobQueue
{
    private const MAX = 200;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function enqueue(
        User $user,
        ?AutomationSession $session,
        AutomationQueueName $queue,
        string $jobType,
        array $payload = [],
        ?int $priority = null,
        ?\DateTimeInterface $availableAt = null,
    ): AutomationQueueJob {
        $pending = AutomationQueueJob::query()
            ->where('status', 'PENDING')
            ->count();
        if ($pending >= self::MAX) {
            throw new \RuntimeException('Automation queue capacity exceeded.');
        }

        return AutomationQueueJob::query()->create([
            'user_id' => $user->id,
            'automation_session_id' => $session?->id,
            'queue_name' => $queue,
            'priority' => $priority ?? $queue->defaultPriority(),
            'job_type' => $jobType,
            'status' => 'PENDING',
            'payload' => $payload,
            'available_at' => $availableAt ?? now(),
        ]);
    }

    public function dequeueNext(): ?AutomationQueueJob
    {
        return DB::transaction(function (): ?AutomationQueueJob {
            $job = AutomationQueueJob::query()
                ->where('status', 'PENDING')
                ->where(function ($q): void {
                    $q->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $job) {
                return null;
            }
            $job->forceFill([
                'status' => 'RUNNING',
                'started_at' => now(),
                'attempts' => $job->attempts + 1,
            ])->save();

            return $job;
        });
    }

    public function complete(AutomationQueueJob $job, bool $ok = true, ?string $error = null): void
    {
        $job->forceFill([
            'status' => $ok ? 'DONE' : 'FAILED',
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }
}
