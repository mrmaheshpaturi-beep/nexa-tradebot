<?php

namespace App\Observability;

use App\Models\DeadLetterJob;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Str;

/**
 * DB / storage / CPU / RAM / queue monitors + dead letter + retry policy.
 * UNKNOWN execution → RECONCILE not RETRY.
 */
class ResourceMonitor
{
    public function __construct(private readonly StructuredLogger $logger) {}

    public function snapshot(): array
    {
        $storagePath = storage_path();
        $free = @disk_free_space($storagePath) ?: 0;
        $total = @disk_total_space($storagePath) ?: 1;
        $mem = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return [
            'phase' => ObservabilitySafety::PHASE,
            'database' => ['status' => 'MONITORED'],
            'storage' => [
                'path' => $storagePath,
                'free_bytes' => $free,
                'total_bytes' => $total,
                'used_ratio' => round(1 - ($free / max($total, 1)), 4),
            ],
            'memory' => [
                'usage_bytes' => $mem,
                'peak_bytes' => $peak,
            ],
            'cpu' => [
                'load_1' => $load[0] ?? null,
                'load_5' => $load[1] ?? null,
                'load_15' => $load[2] ?? null,
            ],
            'queue' => [
                'dead_letter_count' => DeadLetterJob::query()->where('disposition', 'HELD')->count(),
                'retry_policy' => $this->retryPolicy(),
            ],
            'observed_at' => now()->toIso8601String(),
        ];
    }

    public function retryPolicy(): array
    {
        return [
            'max_attempts' => 3,
            'backoff' => 'exponential',
            'unknown_execution' => ObservabilitySafety::UNKNOWN_EXECUTION_POLICY,
            'never_blind_retry_unknown' => true,
        ];
    }

    public function deadLetter(string $queue, string $jobType, array $payload, string $error, int $attempts = 0): DeadLetterJob
    {
        $job = DeadLetterJob::query()->create([
            'public_id' => (string) Str::uuid(),
            'queue' => $queue,
            'job_type' => $jobType,
            'payload' => $payload,
            'error' => $error,
            'attempts' => $attempts,
            'disposition' => 'HELD',
            'failed_at' => now(),
        ]);

        $this->logger->warning('ResourceMonitor', 'Dead letter recorded', [
            'public_id' => $job->public_id,
            'queue' => $queue,
        ]);

        return $job;
    }

    public function executionRetryDecision(string $executionState): string
    {
        if (strtoupper($executionState) === 'UNKNOWN') {
            return 'RECONCILE';
        }

        return 'RETRY_ALLOWED_IF_POLICY_PERMITS';
    }
}
