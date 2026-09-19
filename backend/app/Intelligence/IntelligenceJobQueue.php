<?php

namespace App\Intelligence;

use App\Models\IntelligenceJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Caching, budgets, queues, and failure isolation for intelligence work.
 */
class IntelligenceJobQueue
{
    public const MAX_QUEUE = 50;

    public function enqueue(User $user, string $jobType, array $payload = [], int $priority = 50): IntelligenceJob
    {
        $queued = IntelligenceJob::query()->where('status', 'QUEUED')->count();
        if ($queued >= self::MAX_QUEUE) {
            return IntelligenceJob::query()->create([
                'user_id' => $user->id,
                'job_type' => $jobType,
                'status' => 'FAILED',
                'priority' => $priority,
                'payload' => $payload,
                'error_message' => 'QUEUE_FULL',
                'queued_at' => now(),
                'finished_at' => now(),
            ]);
        }

        return IntelligenceJob::query()->create([
            'user_id' => $user->id,
            'job_type' => $jobType,
            'status' => 'QUEUED',
            'priority' => $priority,
            'payload' => $payload,
            'queued_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function process(int $limit = 5, ?callable $handler = null): array
    {
        $jobs = IntelligenceJob::query()
            ->where('status', 'QUEUED')
            ->orderBy('priority')
            ->orderBy('queued_at')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($jobs as $job) {
            $job->status = 'RUNNING';
            $job->started_at = now();
            $job->attempts++;
            $job->save();

            try {
                $result = $handler ? $handler($job) : ['ok' => true, 'echo' => $job->payload];
                $job->status = 'COMPLETED';
                $job->result = $result;
                $job->finished_at = now();
                $job->save();
                $results[] = ['public_id' => $job->public_id, 'status' => 'COMPLETED'];
            } catch (\Throwable $e) {
                // Failure isolation — one job failure does not abort the batch
                $job->status = $job->attempts >= $job->max_attempts ? 'FAILED' : 'QUEUED';
                $job->error_message = mb_substr($e->getMessage(), 0, 500);
                $job->finished_at = $job->status === 'FAILED' ? now() : null;
                $job->save();
                $results[] = ['public_id' => $job->public_id, 'status' => $job->status, 'error' => $job->error_message];
            }
        }

        return $results;
    }

    public function stats(): array
    {
        return [
            'queued' => IntelligenceJob::query()->where('status', 'QUEUED')->count(),
            'running' => IntelligenceJob::query()->where('status', 'RUNNING')->count(),
            'completed' => IntelligenceJob::query()->where('status', 'COMPLETED')->count(),
            'failed' => IntelligenceJob::query()->where('status', 'FAILED')->count(),
            'max_queue' => self::MAX_QUEUE,
        ];
    }

    public function cacheGet(string $key): mixed
    {
        return Cache::get('intel:'.$key);
    }

    public function cachePut(string $key, mixed $value, int $ttlSeconds = 120): void
    {
        Cache::put('intel:'.$key, $value, $ttlSeconds);
    }
}
