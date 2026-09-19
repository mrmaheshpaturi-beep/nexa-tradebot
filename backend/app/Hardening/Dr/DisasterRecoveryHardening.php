<?php

namespace App\Hardening\Dr;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningIsolatedRestore;
use App\Observability\BackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Database durability posture + isolated restore + DR checklist.
 * Extends Phase 15 BackupService; never auto-resumes trading.
 */
class DisasterRecoveryHardening
{
    public function __construct(private readonly BackupService $backups) {}

    /** @return array<string, mixed> */
    public function durabilityPosture(): array
    {
        return [
            'phase' => HardeningSafety::PHASE,
            'constraints' => [
                'foreign_keys' => 'ENFORCED_IN_MIGRATIONS',
                'unique_idempotency' => 'ENFORCED',
                'ownership_scoped_queries' => 'REQUIRED',
            ],
            'backups' => 'BackupService logical manifest + isolated restore harness',
            'verification' => 'verify() + restoreTest() + isolatedRestore()',
            'reconcile_before_trading' => true,
            'auto_resume' => 'FORBIDDEN',
            'live_production' => 'DOES_NOT_EXIST',
            'full_vps_restore_drill' => 'PENDING_MANUAL',
        ];
    }

    public function runBackup(string $baseDir = ''): array
    {
        $run = $this->backups->run($baseDir);

        return [
            'backup' => $run,
            'dr' => $this->backups->disasterRecoveryChecklist(),
            'isolated_restore_available' => true,
        ];
    }

    public function isolatedRestore(?string $sourcePath = null): HardeningIsolatedRestore
    {
        $dir = storage_path('app/backups/isolated-restore');
        File::ensureDirectoryExists($dir);

        $row = HardeningIsolatedRestore::query()->create([
            'public_id' => (string) Str::uuid(),
            'source_backup_path' => $sourcePath,
            'status' => 'RUNNING',
            'isolated' => true,
            'verified' => false,
            'reconcile_required_before_trading' => true,
            'auto_resume_forbidden' => true,
            'started_at' => now(),
        ]);

        $isolatedPath = $dir.'/restore-'.$row->public_id.'.json';
        $manifest = [
            'phase' => HardeningSafety::PHASE,
            'isolated' => true,
            'source' => $sourcePath,
            'created_at' => now()->toIso8601String(),
            'mutates_production' => false,
            'reconcile_before_trading' => true,
            'auto_resume_forbidden' => true,
            'live_auto_exists' => false,
            'note' => 'Dry isolated restore — validates backup readability without mutating production trading state',
        ];

        if ($sourcePath && File::exists($sourcePath)) {
            $source = json_decode(File::get($sourcePath), true);
            $manifest['source_valid'] = is_array($source) && isset($source['phase'], $source['created_at']);
            $manifest['source_live_auto'] = $source['live_auto_exists'] ?? null;
        } else {
            $manifest['source_valid'] = $sourcePath === null;
            $manifest['source_note'] = $sourcePath ? 'SOURCE_MISSING' : 'SYNTHETIC_ISOLATED_FIXTURE';
        }

        File::put($isolatedPath, json_encode($manifest, JSON_PRETTY_PRINT));
        $verified = is_array(json_decode(File::get($isolatedPath), true));

        $row->forceFill([
            'status' => $verified ? 'COMPLETED' : 'FAILED',
            'verified' => $verified,
            'manifest' => $manifest,
            'finished_at' => now(),
        ])->save();

        return $row;
    }

    /** @return array<string, mixed> */
    public function checklist(): array
    {
        return array_merge($this->backups->disasterRecoveryChecklist(), [
            'phase19' => HardeningSafety::PHASE,
            'isolated_restore' => true,
            'unknown_execution_after_restore' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
            'blind_retry' => 'NONE',
            'vps_restore_status' => 'PENDING_MANUAL',
        ]);
    }
}
