<?php

namespace App\Hardening\Queues;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningDlqJob;
use App\Models\HardeningQueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prioritized / dead-letter / idempotent queues.
 * Redis is optional — DB-backed queue is the default abstraction.
 */
class HardeningJobQueue
{
    public const QUEUE_SAFETY = 'SAFETY';

    public const QUEUE_OPS = 'OPS';

    public const QUEUE_DEFAULT = 'DEFAULT';

    public const PRIORITY = [
        self::QUEUE_SAFETY => 10,
        self::QUEUE_OPS => 50,
        self::QUEUE_DEFAULT => 100,
    ];

    /** @return array<string, mixed> */
    public function backendInfo(): array
    {
        $driver = config('queue.default', 'database');
        $redisConfigured = filled(env('REDIS_HOST'));

        return [
            'abstraction' => 'HardeningJobQueue',
            'driver' => $driver,
            'redis_optional' => true,
            'redis_configured' => $redisConfigured,
            'fallback' => 'DATABASE',
            'note' => $redisConfigured
                ? 'Redis host present — may be used for cache/queue when configured'
                : 'Redis not required; DB-backed prioritized queue is authoritative for Phase 19',
            'dead_letter' => true,
            'idempotent' => true,
            'phase' => HardeningSafety::PHASE,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function enqueue(
        string $queueName,
        string $jobType,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?int $priority = null,
    ): HardeningQueueJob {
        $queue = strtoupper($queueName);
        if ($idempotencyKey) {
            $existing = HardeningQueueJob::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        return HardeningQueueJob::query()->create([
            'public_id' => (string) Str::uuid(),
            'queue_name' => $queue,
            'priority' => $priority ?? (self::PRIORITY[$queue] ?? 100),
            'job_type' => $jobType,
            'idempotency_key' => $idempotencyKey,
            'status' => 'PENDING',
            'payload' => $payload,
            'available_at' => now(),
            'max_attempts' => 3,
        ]);
    }

    public function dequeueNext(): ?HardeningQueueJob
    {
        return DB::transaction(function (): ?HardeningQueueJob {
            $job = HardeningQueueJob::query()
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

    public function complete(HardeningQueueJob $job, bool $ok = true, ?string $error = null): void
    {
        if ($ok) {
            $job->forceFill([
                'status' => 'DONE',
                'error' => null,
                'finished_at' => now(),
            ])->save();

            return;
        }

        if ($job->attempts >= $job->max_attempts) {
            $this->deadLetter($job, $error ?? 'max_attempts');
            $job->forceFill([
                'status' => 'DEAD',
                'error' => $error,
                'finished_at' => now(),
            ])->save();

            return;
        }

        $job->forceFill([
            'status' => 'PENDING',
            'error' => $error,
            'available_at' => now()->addSeconds(5 * $job->attempts),
            'started_at' => null,
        ])->save();
    }

    public function deadLetter(HardeningQueueJob $job, string $error): HardeningDlqJob
    {
        return HardeningDlqJob::query()->create([
            'public_id' => (string) Str::uuid(),
            'source_job_id' => $job->id,
            'queue_name' => $job->queue_name,
            'job_type' => $job->job_type,
            'idempotency_key' => $job->idempotency_key,
            'payload' => $job->payload,
            'error' => $error,
            'status' => 'DEAD',
            'dead_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'backend' => $this->backendInfo(),
            'pending' => HardeningQueueJob::query()->where('status', 'PENDING')->count(),
            'running' => HardeningQueueJob::query()->where('status', 'RUNNING')->count(),
            'done' => HardeningQueueJob::query()->where('status', 'DONE')->count(),
            'dead' => HardeningDlqJob::query()->count(),
            'safety_preempts_default' => true,
        ];
    }
}
