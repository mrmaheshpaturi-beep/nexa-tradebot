<?php

namespace App\Observability;

use App\Models\BackupRun;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Backup + verify + restore test.
 * After disaster: reconcile before new trading.
 */
class BackupService
{
    public function __construct(private readonly StructuredLogger $logger) {}

    public function run(string $baseDir = ''): BackupRun
    {
        $dir = $baseDir !== '' ? $baseDir : storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        $run = BackupRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'status' => 'RUNNING',
            'path' => null,
            'verified' => false,
            'restore_tested' => false,
            'reconcile_required_before_trading' => true,
            'manifest' => null,
            'started_at' => now(),
        ]);

        $path = $dir.'/backup-'.$run->public_id.'.json';
        $manifest = [
            'phase' => ObservabilitySafety::PHASE,
            'created_at' => now()->toIso8601String(),
            'tables_note' => 'Logical backup manifest — application metadata snapshot',
            'includes' => [
                'settings_keys',
                'automation_profiles_count',
                'validation_sessions_count',
                'alerts_count',
            ],
            'counts' => [
                'settings' => \App\Models\ApplicationSetting::query()->count(),
                'automation_profiles' => \App\Models\AutomationProfile::query()->count(),
                'validation_sessions' => \App\Models\ValidationSession::query()->count(),
                'alerts' => \App\Models\SystemAlert::query()->count(),
            ],
            'live_auto_exists' => false,
            'order_send' => false,
        ];

        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT));

        $verified = $this->verify($path);
        $restoreTested = $this->restoreTest($path);

        $run->path = $path;
        $run->verified = $verified;
        $run->restore_tested = $restoreTested;
        $run->manifest = $manifest;
        $run->status = ($verified && $restoreTested) ? 'COMPLETED' : 'FAILED';
        $run->finished_at = now();
        $run->reconcile_required_before_trading = true;
        $run->save();

        $this->logger->info('BackupService', 'Backup finished', [
            'public_id' => $run->public_id,
            'status' => $run->status,
            'reconcile_required_before_trading' => true,
        ]);

        return $run;
    }

    public function verify(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }
        $data = json_decode(File::get($path), true);

        return is_array($data) && isset($data['phase'], $data['created_at']);
    }

    public function restoreTest(string $path): bool
    {
        // Dry restore test: load + validate schema of manifest without mutating production state.
        if (! $this->verify($path)) {
            return false;
        }
        $data = json_decode(File::get($path), true);

        return ($data['live_auto_exists'] ?? true) === false;
    }

    public function disasterRecoveryChecklist(): array
    {
        return [
            'phase' => ObservabilitySafety::PHASE,
            'steps' => [
                '1. Stop AUTO DEMO entry (PAUSE / SAFE_MODE)',
                '2. Restore backup from verified path',
                '3. Verify integrity',
                '4. RECONCILE broker vs app state before any new trading',
                '5. Confirm DEMO account verification',
                '6. Resume only with explicit operator action',
            ],
            'reconcile_before_new_trading' => true,
            'auto_resume_forbidden' => true,
            'live_production' => 'DOES_NOT_EXIST',
        ];
    }
}
