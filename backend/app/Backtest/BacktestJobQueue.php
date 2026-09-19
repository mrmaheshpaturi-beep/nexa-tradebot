<?php

namespace App\Backtest;

use App\Models\BacktestJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bounded queued jobs — rejects when active queue exceeds max.
 */
class BacktestJobQueue
{
    public const MAX_QUEUED_PER_USER = 10;
    public const MAX_GLOBAL_QUEUED = 50;

    public function enqueue(User $user, string $jobType, array $payload, ?int $runId = null, int $priority = 50): BacktestJob
    {
        $userQueued = BacktestJob::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['QUEUED', 'RUNNING'])
            ->count();
        if ($userQueued >= self::MAX_QUEUED_PER_USER) {
            throw ValidationException::withMessages([
                'queue' => 'Per-user backtest queue limit reached ('.self::MAX_QUEUED_PER_USER.').',
            ]);
        }
        $globalQueued = BacktestJob::query()->whereIn('status', ['QUEUED', 'RUNNING'])->count();
        if ($globalQueued >= self::MAX_GLOBAL_QUEUED) {
            throw ValidationException::withMessages([
                'queue' => 'Global backtest queue limit reached ('.self::MAX_GLOBAL_QUEUED.').',
            ]);
        }

        return BacktestJob::query()->create([
            'user_id' => $user->id,
            'backtest_run_id' => $runId,
            'job_type' => $jobType,
            'status' => 'QUEUED',
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => 3,
            'payload' => $payload,
            'queued_at' => now(),
        ]);
    }

    /** @return list<BacktestJob> */
    public function drain(int $limit = 5): array
    {
        return DB::transaction(function () use ($limit) {
            $jobs = BacktestJob::query()
                ->where('status', 'QUEUED')
                ->orderBy('priority')
                ->orderBy('queued_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();
            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => 'RUNNING',
                    'started_at' => now(),
                    'attempts' => $job->attempts + 1,
                ])->save();
            }

            return $jobs->all();
        });
    }

    public function complete(BacktestJob $job, bool $ok, ?string $error = null): void
    {
        $job->forceFill([
            'status' => $ok ? 'COMPLETED' : 'FAILED',
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    public function stats(?User $user = null): array
    {
        $base = BacktestJob::query();
        if ($user) {
            $base->where('user_id', $user->id);
        }

        return [
            'queued' => (clone $base)->where('status', 'QUEUED')->count(),
            'running' => (clone $base)->where('status', 'RUNNING')->count(),
            'max_queued_per_user' => self::MAX_QUEUED_PER_USER,
            'max_global_queued' => self::MAX_GLOBAL_QUEUED,
            'bounded' => true,
        ];
    }
}
