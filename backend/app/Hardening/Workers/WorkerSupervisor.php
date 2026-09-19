<?php

namespace App\Hardening\Workers;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningWorkerProcess;
use Illuminate\Support\Str;

/**
 * Supervised workers (Laravel / Python / MT5 node metadata) with graceful shutdown
 * and mandatory restart reconciliation before trading resumes.
 */
class WorkerSupervisor
{
    /** @var list<string> */
    public const KINDS = ['LARAVEL_QUEUE', 'PYTHON_BRIDGE', 'MT5_TERMINAL', 'AUTOMATION_WORKER'];

    public function register(string $kind, string $label, array $metadata = []): HardeningWorkerProcess
    {
        $kind = strtoupper($kind);
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown worker kind');
        }

        return HardeningWorkerProcess::query()->create([
            'public_id' => (string) Str::uuid(),
            'worker_kind' => $kind,
            'label' => $label,
            'status' => 'REGISTERED',
            'graceful_shutdown' => false,
            'restart_reconcile_required' => true,
            'reconcile_completed' => false,
            'metadata' => array_merge([
                'phase' => HardeningSafety::PHASE,
                'order_send' => false,
                'unknown_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
            ], $metadata),
        ]);
    }

    public function start(HardeningWorkerProcess $worker): HardeningWorkerProcess
    {
        $worker->forceFill([
            'status' => 'RUNNING',
            'started_at' => now(),
            'stopped_at' => null,
            'graceful_shutdown' => false,
            'last_heartbeat_at' => now(),
            // Restart always requires reconcile before trading resume
            'restart_reconcile_required' => true,
            'reconcile_completed' => false,
        ])->save();

        return $worker;
    }

    public function heartbeat(HardeningWorkerProcess $worker): HardeningWorkerProcess
    {
        $worker->forceFill(['last_heartbeat_at' => now()])->save();

        return $worker;
    }

    public function requestGracefulShutdown(HardeningWorkerProcess $worker): HardeningWorkerProcess
    {
        $worker->forceFill([
            'graceful_shutdown' => true,
            'status' => 'SHUTTING_DOWN',
        ])->save();

        return $worker;
    }

    public function stop(HardeningWorkerProcess $worker): HardeningWorkerProcess
    {
        $worker->forceFill([
            'status' => 'STOPPED',
            'stopped_at' => now(),
            'graceful_shutdown' => true,
            'restart_reconcile_required' => true,
            'reconcile_completed' => false,
        ])->save();

        return $worker;
    }

    /**
     * Mark restart reconciliation complete — NEVER implies blind retry of UNKNOWN executions.
     */
    public function completeRestartReconciliation(HardeningWorkerProcess $worker): HardeningWorkerProcess
    {
        $worker->forceFill([
            'reconcile_completed' => true,
            'restart_reconcile_required' => false,
            'metadata' => array_merge($worker->metadata ?? [], [
                'reconcile_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
                'blind_retry' => 'NONE',
            ]),
        ])->save();

        return $worker;
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $rows = HardeningWorkerProcess::query()->orderByDesc('id')->limit(50)->get();

        return [
            'phase' => HardeningSafety::PHASE,
            'kinds' => self::KINDS,
            'workers' => $rows,
            'trading_resume_requires_reconcile' => true,
            'blind_retry_on_unknown' => 'NONE',
            'mt5_real_terminal' => 'PENDING_MANUAL_VALIDATION',
        ];
    }
}
