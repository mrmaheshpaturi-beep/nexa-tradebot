<?php

namespace App\Automation;

use App\Enums\AutomationLockType;
use App\Enums\AutomationMode;
use App\Enums\AutomationState;
use App\Models\AutomationSession;
use App\Models\User;

/**
 * Restart recovery: restore state, broker sync hook, AUTO ENTRY PAUSED, require resume.
 * Does not auto-resume broker execution.
 */
class AutomationCrashRecoveryService
{
    public function __construct(
        private readonly AutomationEventRecorder $events,
        private readonly AutomationExecutionLockService $locks,
        private readonly AutomationNotificationService $notifications,
        private readonly AutomationStartupGate $gate,
    ) {}

    public function recover(User $user, AutomationSession $session): AutomationSession
    {
        abort_unless($session->user_id === $user->id, 404);

        // Never leave RUNNING across crash — force entry pause
        if (in_array($session->state, [
            AutomationState::Running,
            AutomationState::Starting,
            AutomationState::Degraded,
            AutomationState::Stopping,
        ], true)) {
            $session->forceFill([
                'state' => AutomationState::Paused,
                'auto_entry_paused' => true,
                'pause_reason' => 'RESTART_RECOVERY',
                'paused_at' => now(),
            ])->save();
        }

        $this->locks->acquire($user, $session, AutomationLockType::Entry, 'RESTART_RECOVERY_AUTO_ENTRY_PAUSED', null, null, [
            'require_resume' => true,
        ]);

        if ($session->mode === AutomationMode::DemoAuto && $session->brokerAccount) {
            $acct = $this->gate->verifyAccount($session->brokerAccount, true);
            if (! ($acct['ok'] ?? false)) {
                $this->gate->enterSafeMode($session, $acct['blocker'] ?? 'RECOVERY_ACCOUNT_UNSAFE');
            }
        }

        $this->events->record($user, $session, 'RESTART_RECOVERY', [
            'auto_entry_paused' => true,
            'require_resume' => true,
            'broker_sync' => 'HOOKED',
            'note' => 'MT5/VPS recovery: verify DEMO account, reconcile UNKNOWN workflows, then explicit resume.',
        ], 'WARN');

        $this->notifications->notify(
            $user,
            $session,
            'Automation recovered (entries paused)',
            'After restart, AUTO ENTRY is PAUSED. Review pre-flight and resume explicitly.',
            'WARN',
        );

        return $session->fresh();
    }
}
