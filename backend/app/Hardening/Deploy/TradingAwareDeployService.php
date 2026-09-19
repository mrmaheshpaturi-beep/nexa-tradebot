<?php

namespace App\Hardening\Deploy;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningDeployVersion;
use App\Models\TradingNodeLease;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Trading-aware deploy / maintenance / rollback / versioning.
 * Extends Phase 18 lease foundation for safe failover.
 */
class TradingAwareDeployService
{
    public function register(User $user, string $version, ?string $gitSha = null): HardeningDeployVersion
    {
        return HardeningDeployVersion::query()->create([
            'public_id' => (string) Str::uuid(),
            'version' => $version,
            'git_sha' => $gitSha,
            'status' => 'REGISTERED',
            'maintenance_mode' => false,
            'trading_paused' => false,
            'reconcile_before_resume' => true,
            'checklist' => $this->deployChecklist(),
            'registered_by' => $user->id,
        ]);
    }

    /** @return array<string, mixed> */
    public function deployChecklist(): array
    {
        return [
            '1_pause_new_entries' => true,
            '2_enter_maintenance' => true,
            '3_drain_queues' => true,
            '4_deploy_build' => true,
            '5_migrate' => true,
            '6_health_liveness_readiness' => true,
            '7_trading_readiness_gate' => true,
            '8_reconcile_unknown_before_resume' => true,
            '9_explicit_operator_resume' => true,
            'blind_retry_forbidden' => true,
            'live_auto' => 'DOES_NOT_EXIST',
        ];
    }

    public function enterMaintenance(HardeningDeployVersion $deploy): HardeningDeployVersion
    {
        $deploy->forceFill([
            'maintenance_mode' => true,
            'trading_paused' => true,
            'status' => 'MAINTENANCE',
            'reconcile_before_resume' => true,
        ])->save();

        return $deploy;
    }

    public function activate(HardeningDeployVersion $deploy): HardeningDeployVersion
    {
        HardeningDeployVersion::query()
            ->where('status', 'ACTIVE')
            ->update(['status' => 'SUPERSEDED']);

        $deploy->forceFill([
            'status' => 'ACTIVE',
            'maintenance_mode' => false,
            'activated_at' => now(),
            // Trading remains paused until reconcile + explicit resume
            'trading_paused' => true,
            'reconcile_before_resume' => true,
        ])->save();

        return $deploy;
    }

    public function markReconciledAndResume(HardeningDeployVersion $deploy): HardeningDeployVersion
    {
        if (! $deploy->reconcile_before_resume) {
            throw new \RuntimeException('Deploy resume requires reconcile_before_resume policy.');
        }
        $deploy->forceFill([
            'trading_paused' => false,
            'reconcile_before_resume' => false,
            'checklist' => array_merge($deploy->checklist ?? [], [
                'resumed_at' => now()->toIso8601String(),
                'unknown_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
            ]),
        ])->save();

        return $deploy;
    }

    public function rollback(HardeningDeployVersion $current, HardeningDeployVersion $target): HardeningDeployVersion
    {
        $current->forceFill([
            'status' => 'ROLLED_BACK',
            'rolled_back_at' => now(),
            'trading_paused' => true,
            'maintenance_mode' => true,
            'reconcile_before_resume' => true,
            'rollback_of' => ['to' => $target->public_id, 'version' => $target->version],
        ])->save();

        return $this->activate($target);
    }

    /**
     * Safe failover: refuse if split-brain lease detected for account scope.
     *
     * @return array<string, mixed>
     */
    public function safeFailoverCheck(?int $fleetAccountId = null): array
    {
        $q = TradingNodeLease::query()->where('split_brain_detected', true)->where('status', 'SPLIT_BRAIN_BLOCKED');
        if ($fleetAccountId) {
            $q->where('fleet_account_id', $fleetAccountId);
        }
        $blocked = $q->exists();

        return [
            'failover_allowed' => ! $blocked,
            'split_brain_detected' => $blocked,
            'policy' => 'SAFE_MODE_ON_SPLIT_BRAIN',
            'extends_phase_18_leases' => true,
            'blind_retry' => 'NONE',
        ];
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return [
            'phase' => HardeningSafety::PHASE,
            'versions' => HardeningDeployVersion::query()->orderByDesc('id')->limit(20)->get(),
            'checklist' => $this->deployChecklist(),
            'failover' => $this->safeFailoverCheck(),
            'live_auto_exists' => false,
        ];
    }
}
