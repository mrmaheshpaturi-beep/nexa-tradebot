<?php

namespace App\Automation;

use App\Automation\Support\AutomationSafety;
use App\Enums\AutomationLockType;
use App\Enums\AutomationMode;
use App\Enums\AutomationProfileStatus;
use App\Enums\AutomationQueueName;
use App\Enums\AutomationState;
use App\Models\AutomationDailyCounter;
use App\Models\AutomationProfile;
use App\Models\AutomationSession;
use App\Models\BrokerAccount;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Automated DEMO Trading Orchestrator.
 * Coordinates Market Data → Scanner → Signals → Candidates → Intelligence →
 * Qualification → Risk → Execution (Phase 10) → Management (Phase 11) → Analytics (Phase 12).
 *
 * Never calls order_send. Never auto-starts on boot. LIVE_AUTO does not exist.
 */
class AutomatedTradingOrchestrator
{
    public function __construct(
        private readonly AutomationStartupGate $startupGate,
        private readonly AutomationProfileService $profiles,
        private readonly AutomationTradeWorkflowService $workflows,
        private readonly AutomationExecutionLockService $locks,
        private readonly AutomationJobQueue $queue,
        private readonly AutomationEventRecorder $events,
        private readonly AutomationNotificationService $notifications,
        private readonly AutomationCrashRecoveryService $recovery,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function controlCenter(User $user): array
    {
        $session = AutomationSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('state', [AutomationState::Off->value, AutomationState::Error->value])
            ->latest('id')
            ->first()
            ?? AutomationSession::query()->where('user_id', $user->id)->latest('id')->first();

        $activeProfile = AutomationProfile::query()
            ->where('user_id', $user->id)
            ->where('status', AutomationProfileStatus::Active)
            ->latest('id')
            ->first();

        return [
            'engine_version' => AutomationSafety::ENGINE_VERSION,
            'phase' => 14,
            'default_state' => AutomationState::Off->value,
            'auto_start_on_boot' => false,
            'modes_allowed' => AutomationSafety::ALLOWED_MODES,
            'modes_forbidden' => AutomationSafety::FORBIDDEN_MODES,
            'live_auto_exists' => false,
            'ui_label' => AutomationSafety::UI_LABEL_DEMO,
            'ui_live_forbidden' => AutomationSafety::UI_LABEL_LIVE_FORBIDDEN,
            'order_send' => [
                'phase14_sites' => AutomationSafety::ORDER_SEND_CALL_SITES_IN_PHASE_14,
                'sole_path' => AutomationSafety::PHASE_10_ORDER_SEND,
            ],
            'settings' => [
                'allow_demo_execution' => $this->settings->value('allow_demo_execution') === true,
                'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
                'allow_live_execution' => false,
                'emergency_stop' => $this->settings->value('emergency_stop') !== false,
            ],
            'session' => $session?->load(['profile', 'brokerAccount']),
            'active_profile' => $activeProfile,
            'pipelines' => [
                'market_data' => true,
                'scanner' => true,
                'signals' => true,
                'intelligence' => true,
                'qualification' => true,
                'risk' => true,
                'execution_phase10' => true,
                'management_phase11' => true,
                'analytics_phase12' => true,
            ],
            'banners' => [
                'auto_demo' => $session?->mode === AutomationMode::DemoAuto ? 'AUTO DEMO TRADING' : null,
                'auto_live' => 'AUTO LIVE — NOT AVAILABLE',
                'safe_mode' => $session?->safe_mode ? true : false,
                'kill_switch' => $session?->kill_switch ? true : false,
            ],
        ];
    }

    /**
     * Two-step start for DEMO_AUTO: (1) preflight + enable confirm, (2) start session.
     *
     * @param  array<string,mixed>  $input
     * @return array{session:AutomationSession,preflight:array<string,mixed>,step:int}
     */
    public function startStep1(User $user, array $input, Request $request): array
    {
        $modeRaw = strtoupper((string) ($input['mode'] ?? 'DRY_RUN'));
        AutomationSafety::assertModeAllowed($modeRaw);
        $mode = AutomationMode::from($modeRaw);

        if ($mode === AutomationMode::Off) {
            throw ValidationException::withMessages(['mode' => 'Cannot start in OFF mode.']);
        }

        $confirmPhrase = (string) ($input['confirmation_phrase'] ?? '');
        $required = $mode === AutomationMode::DemoAuto
            ? 'ENABLE AUTO DEMO TRADING'
            : 'ENABLE DRY RUN';
        if ($confirmPhrase !== $required) {
            throw ValidationException::withMessages([
                'confirmation_phrase' => "Step 1 requires exact phrase: {$required}",
            ]);
        }

        $profile = $this->resolveProfile($user, $input['profile_public_id'] ?? null);
        if ($profile->status !== AutomationProfileStatus::Active) {
            throw ValidationException::withMessages(['profile' => 'Active validated profile required.']);
        }

        $account = null;
        if ($mode === AutomationMode::DemoAuto) {
            $accountId = $input['broker_account_public_id'] ?? null;
            $account = BrokerAccount::query()
                ->where('user_id', $user->id)
                ->when($accountId, fn ($q) => $q->where('public_id', $accountId))
                ->where('environment', 'DEMO')
                ->first();
            if (! $account) {
                throw ValidationException::withMessages(['account' => 'DEMO broker account required.']);
            }
        }

        $preflight = $this->startupGate->preflight($user, $mode, $account);
        if (! $preflight['ok'] && $mode === AutomationMode::DemoAuto) {
            throw ValidationException::withMessages([
                'preflight' => implode(',', $preflight['blockers']),
            ]);
        }

        // Persist a STARTING session pending step 2
        $snapshot = [
            'profile_public_id' => $profile->public_id,
            'profile_version' => $profile->version,
            'config_hash' => $profile->config_hash,
            'mode' => $mode->value,
            'preflight' => $preflight,
            'step1_at' => now()->toIso8601String(),
        ];

        $session = AutomationSession::query()->create([
            'user_id' => $user->id,
            'automation_profile_id' => $profile->id,
            'broker_account_id' => $account?->id,
            'mode' => $mode,
            'state' => AutomationState::Starting,
            'config_snapshot_hash' => hash('sha256', json_encode($snapshot)),
            'config_snapshot' => $snapshot,
            'auto_entry_paused' => true,
            'entries_blocked' => false,
            'safe_mode' => false,
            'kill_switch' => false,
            'account_trade_mode' => $preflight['checks']['account']['trade_mode'] ?? null,
            'preflight' => $preflight,
            'counters' => ['trades_today' => 0, 'open' => 0, 'loss_streak' => 0],
        ]);

        $this->events->record($user, $session, 'START_STEP1', [
            'mode' => $mode->value,
            'label' => $mode->label(),
        ], 'AUDIT');
        $this->audit->record('automation.start.step1', $session, [], [
            'mode' => $mode->value,
            'profile' => $profile->public_id,
        ], $request);

        return ['session' => $session->fresh(['profile', 'brokerAccount']), 'preflight' => $preflight, 'step' => 1];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function startStep2(User $user, AutomationSession $session, array $input, Request $request): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);
        if ($session->state !== AutomationState::Starting) {
            throw ValidationException::withMessages(['session' => 'Session is not awaiting start step 2.']);
        }

        $confirm = (string) ($input['confirmation_phrase'] ?? '');
        $required = $session->mode === AutomationMode::DemoAuto
            ? 'CONFIRM AUTO DEMO START'
            : 'CONFIRM DRY RUN START';
        if ($confirm !== $required) {
            throw ValidationException::withMessages([
                'confirmation_phrase' => "Step 2 requires exact phrase: {$required}",
            ]);
        }

        // Re-check account for DEMO_AUTO
        if ($session->mode === AutomationMode::DemoAuto) {
            $preflight = $this->startupGate->preflight($user, $session->mode, $session->brokerAccount);
            if (! $preflight['ok']) {
                $this->startupGate->enterSafeMode($session, implode(',', $preflight['blockers']));
                throw ValidationException::withMessages(['preflight' => implode(',', $preflight['blockers'])]);
            }
            $session->preflight = $preflight;
            $session->account_trade_mode = $preflight['checks']['account']['trade_mode'] ?? 'DEMO';
        }

        $session->forceFill([
            'state' => AutomationState::Running,
            'started_at' => now(),
            'auto_entry_paused' => false,
            'last_heartbeat_at' => now(),
        ])->save();

        $this->pulseHeartbeat();
        $this->events->record($user, $session, 'START_STEP2_RUNNING', [
            'mode' => $session->mode->value,
            'ui' => $session->mode->label(),
        ], 'AUDIT');
        $this->notifications->notify($user, $session, 'Automation started', $session->mode->label().' is RUNNING.', 'INFO');
        $this->audit->record('automation.start.step2', $session, [], ['state' => 'RUNNING'], $request);

        return $session->fresh(['profile', 'brokerAccount']);
    }

    public function pause(User $user, AutomationSession $session, string $reason = 'OPERATOR_PAUSE'): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);
        $session->forceFill([
            'state' => AutomationState::Paused,
            'auto_entry_paused' => true,
            'paused_at' => now(),
            'pause_reason' => $reason,
        ])->save();
        $this->locks->acquire($user, $session, AutomationLockType::Entry, $reason);
        $this->events->record($user, $session, 'PAUSED', ['reason' => $reason], 'WARN');

        return $session->fresh();
    }

    public function resume(User $user, AutomationSession $session, Request $request): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);
        if ($session->kill_switch) {
            throw ValidationException::withMessages(['kill_switch' => 'Kill switch active — cannot resume.']);
        }
        if ($session->safe_mode) {
            // Critical: explicit resume after re-verify
            if ($session->mode === AutomationMode::DemoAuto) {
                $preflight = $this->startupGate->preflight($user, $session->mode, $session->brokerAccount);
                if (! $preflight['ok']) {
                    throw ValidationException::withMessages(['preflight' => implode(',', $preflight['blockers'])]);
                }
            }
            $session->safe_mode = false;
            $session->safe_mode_reason = null;
            $session->entries_blocked = false;
        }

        foreach ($this->locks->activeBlocking($session) as $lock) {
            if (in_array($lock->lock_type, [AutomationLockType::Entry, AutomationLockType::SafeMode, AutomationLockType::Session], true)) {
                $this->locks->release($lock, $user);
            }
        }

        $session->forceFill([
            'state' => AutomationState::Running,
            'auto_entry_paused' => false,
            'pause_reason' => null,
            'paused_at' => null,
            'last_heartbeat_at' => now(),
        ])->save();
        $this->events->record($user, $session, 'RESUMED', [], 'AUDIT');
        $this->audit->record('automation.resume', $session, [], ['state' => 'RUNNING'], $request);

        return $session->fresh();
    }

    public function stop(User $user, AutomationSession $session, Request $request): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);
        $session->forceFill([
            'state' => AutomationState::Stopping,
            'auto_entry_paused' => true,
            'entries_blocked' => true,
        ])->save();
        $session->forceFill([
            'state' => AutomationState::Off,
            'stopped_at' => now(),
        ])->save();
        $this->events->record($user, $session, 'STOPPED', [], 'AUDIT');
        $this->audit->record('automation.stop', $session, [], ['state' => 'OFF'], $request);

        return $session->fresh();
    }

    /**
     * Kill switch — blocks entries; does NOT auto close-all.
     */
    public function killSwitch(User $user, AutomationSession $session, Request $request): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);
        $session->forceFill([
            'kill_switch' => true,
            'auto_entry_paused' => true,
            'entries_blocked' => true,
            'state' => AutomationState::Paused,
            'pause_reason' => 'KILL_SWITCH',
        ])->save();
        $this->locks->acquire($user, $session, AutomationLockType::Kill, 'KILL_SWITCH');
        $this->events->record($user, $session, 'KILL_SWITCH', [
            'close_all' => false,
            'note' => 'EMERGENCY STOP / kill switch does not auto close-all. CLOSE-ALL is separate.',
        ], 'CRITICAL');
        $this->notifications->notify($user, $session, 'Kill switch armed', 'Entries blocked. Positions not auto-closed.', 'CRITICAL');
        $this->audit->record('automation.kill_switch', $session, [], ['close_all' => false], $request);

        // Also set emergency_stop setting foundation (operator may clear separately)
        return $session->fresh();
    }

    /**
     * Single modular tick — not a giant runEverythingForever.
     *
     * @param  array<string,mixed>  $candidate
     * @return array{workflow:?\App\Models\AutomationWorkflow,session:AutomationSession,skipped:?string}
     */
    public function tick(User $user, AutomationSession $session, array $candidate = []): array
    {
        abort_unless($session->user_id === $user->id, 404);
        $this->pulseHeartbeat();
        $session->forceFill([
            'last_heartbeat_at' => now(),
            'last_tick_at' => now(),
            'tick_count' => $session->tick_count + 1,
        ])->save();

        if (! in_array($session->state, [AutomationState::Running, AutomationState::Degraded], true)) {
            return ['workflow' => null, 'session' => $session->fresh(), 'skipped' => 'STATE_'.$session->state->value];
        }
        if ($session->auto_entry_paused) {
            return ['workflow' => null, 'session' => $session->fresh(), 'skipped' => 'AUTO_ENTRY_PAUSED'];
        }

        // Continuous account recheck for DEMO_AUTO
        if ($session->mode === AutomationMode::DemoAuto && $session->brokerAccount) {
            $acct = $this->startupGate->verifyAccount($session->brokerAccount, true);
            $session->last_account_check_at = now()->toIso8601String();
            $session->save();
            if (! ($acct['ok'] ?? false)) {
                $this->startupGate->enterSafeMode($session, $acct['blocker'] ?? 'ACCOUNT_RECHECK_FAILED');

                return ['workflow' => null, 'session' => $session->fresh(), 'skipped' => 'SAFE_MODE'];
            }
        }

        if ($candidate === []) {
            // Enqueue scan job (modular) — no giant forever loop
            $this->queue->enqueue($user, $session, AutomationQueueName::Scan, 'SCAN_UNIVERSE', [
                'profile' => $session->automation_profile_id,
            ]);

            return ['workflow' => null, 'session' => $session->fresh(), 'skipped' => 'NO_CANDIDATE_ENQUEUED_SCAN'];
        }

        $profile = $session->profile;
        $workflow = $this->workflows->startFromCandidate($user, $session, $profile, $candidate);

        // Persist daily counters skeleton
        $day = now()->utc()->toDateString();
        AutomationDailyCounter::query()->firstOrCreate(
            ['user_id' => $user->id, 'day_key' => $day, 'symbol' => $workflow->symbol],
            ['automation_session_id' => $session->id]
        );

        return ['workflow' => $workflow, 'session' => $session->fresh(), 'skipped' => null];
    }

    public function recoverAfterRestart(User $user, AutomationSession $session): AutomationSession
    {
        return $this->recovery->recover($user, $session);
    }

    private function resolveProfile(User $user, ?string $publicId): AutomationProfile
    {
        if ($publicId) {
            $profile = AutomationProfile::query()->where('user_id', $user->id)->where('public_id', $publicId)->firstOrFail();
        } else {
            $profile = AutomationProfile::query()
                ->where('user_id', $user->id)
                ->where('status', AutomationProfileStatus::Active)
                ->latest('id')
                ->first();
        }
        if (! $profile) {
            throw ValidationException::withMessages(['profile' => 'No active automation profile.']);
        }

        return $profile;
    }

    private function pulseHeartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'AUTOMATED_TRADING_ORCHESTRATOR',
            'instance_id' => 'phase-14-automation',
            'status' => 'ONLINE',
            'environment' => 'DEMO',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => [
                'engine_version' => AutomationSafety::ENGINE_VERSION,
                'order_send_phase14' => 0,
                'live_auto' => false,
            ],
            'metadata' => ['phase' => 14],
        ]);
    }
}
