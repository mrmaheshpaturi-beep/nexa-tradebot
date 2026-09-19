<?php

namespace App\Automation;

use App\Automation\Support\AutomationSafety;
use App\Enums\AccountTradeMode;
use App\Enums\AutomationLockType;
use App\Enums\AutomationMode;
use App\Enums\AutomationState;
use App\Enums\TradingEnvironment;
use App\Execution\DemoAccountVerifier;
use App\Models\AutomationSession;
use App\Models\BrokerAccount;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Startup + continuous safety gate. LIVE/UNKNOWN/AMBIGUOUS → SAFE_MODE, zero broker-changing actions.
 */
class AutomationStartupGate
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DemoAccountVerifier $verifier,
        private readonly AutomationExecutionLockService $locks,
        private readonly AutomationEventRecorder $events,
    ) {}

    /**
     * @return array{ok:bool,checks:array<string,mixed>,blockers:list<string>}
     */
    public function preflight(User $user, AutomationMode $mode, ?BrokerAccount $account = null): array
    {
        $checks = [];
        $blockers = [];

        $checks['engine_version'] = AutomationSafety::ENGINE_VERSION;
        $checks['mode'] = $mode->value;
        $checks['mode_label'] = $mode->label();
        $checks['live_auto_exists'] = false;
        $checks['phase14_order_send_sites'] = AutomationSafety::ORDER_SEND_CALL_SITES_IN_PHASE_14;
        $checks['phase10_order_send'] = AutomationSafety::PHASE_10_ORDER_SEND;
        $checks['default_state_after_install'] = AutomationState::Off->value;
        $checks['auto_start_on_boot'] = false;

        $emergency = $this->settings->value('emergency_stop') !== false;
        $checks['emergency_stop'] = $emergency;
        if ($emergency) {
            $blockers[] = 'EMERGENCY_STOP_ACTIVE';
        }

        $allowLive = $this->settings->value('allow_live_execution') === true;
        $checks['allow_live_execution'] = $allowLive;
        if ($allowLive) {
            $blockers[] = 'LIVE_FLAG_MUST_REMAIN_FALSE';
        }

        $allowDemo = $this->settings->value('allow_demo_execution') === true;
        $autoDemo = $this->settings->value('auto_demo_execution') === true;
        $checks['allow_demo_execution'] = $allowDemo;
        $checks['auto_demo_execution'] = $autoDemo;

        if ($mode === AutomationMode::DemoAuto) {
            if (! $allowDemo) {
                $blockers[] = 'ALLOW_DEMO_EXECUTION_REQUIRED';
            }
            if (! $autoDemo) {
                $blockers[] = 'AUTO_DEMO_EXECUTION_REQUIRED';
            }
            if (! $account) {
                $blockers[] = 'DEMO_ACCOUNT_REQUIRED';
            } else {
                $accountCheck = $this->verifyAccount($account, true);
                $checks['account'] = $accountCheck;
                if (! ($accountCheck['ok'] ?? false)) {
                    $blockers[] = $accountCheck['blocker'] ?? 'ACCOUNT_VERIFICATION_FAILED';
                }
            }
        } elseif ($mode === AutomationMode::DryRun) {
            $checks['broker_writes'] = 'ZERO';
            $checks['account'] = $account ? $this->verifyAccount($account, false) : ['ok' => true, 'skipped' => true];
        } else {
            $blockers[] = 'MODE_IS_OFF';
        }

        // Health dependencies (soft for DRY_RUN display; hard for DEMO_AUTO)
        $heartbeats = [
            'MARKET_DATA' => $this->heartbeatFresh('MARKET_DATA'),
            'RISK_ENGINE' => $this->heartbeatFresh('RISK_ENGINE') || $this->heartbeatFresh('RISK'),
            'TRADE_INTELLIGENCE' => $this->heartbeatFresh('TRADE_INTELLIGENCE'),
        ];
        $checks['heartbeats'] = $heartbeats;
        if ($mode === AutomationMode::DemoAuto) {
            foreach ($heartbeats as $name => $ok) {
                if (! $ok && $name !== 'TRADE_INTELLIGENCE') {
                    // intelligence may be WAIT path; market+risk required
                    if (in_array($name, ['MARKET_DATA', 'RISK_ENGINE'], true)) {
                        // soft degrade — orchestrator may enter DEGRADED
                        $checks['health_warnings'][] = "HEARTBEAT_STALE_{$name}";
                    }
                }
            }
        }

        $checks['ui_banner'] = $mode === AutomationMode::DemoAuto
            ? AutomationSafety::UI_LABEL_DEMO
            : ($mode === AutomationMode::DryRun ? 'DRY RUN — ZERO BROKER' : 'AUTOMATION OFF');
        $checks['auto_live_banner'] = AutomationSafety::UI_LABEL_LIVE_FORBIDDEN;

        return [
            'ok' => $blockers === [],
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    /**
     * Continuous gate before any broker-changing action.
     *
     * @throws ValidationException
     */
    public function assertSafeForBrokerAction(AutomationSession $session): void
    {
        if ($session->mode !== AutomationMode::DemoAuto) {
            throw ValidationException::withMessages([
                'mode' => 'Broker-changing actions require DEMO_AUTO mode.',
            ]);
        }
        if ($session->kill_switch || $session->safe_mode || $session->entries_blocked) {
            throw ValidationException::withMessages([
                'session' => 'Session is in SAFE_MODE / kill / entries-blocked; broker actions forbidden.',
            ]);
        }
        if (in_array($session->state, [AutomationState::SafeMode, AutomationState::Error, AutomationState::Off, AutomationState::Stopping], true)) {
            throw ValidationException::withMessages([
                'state' => 'Session state forbids broker actions: '.$session->state->value,
            ]);
        }
        if ($this->settings->value('emergency_stop') !== false) {
            throw ValidationException::withMessages(['emergency_stop' => 'Emergency stop is active.']);
        }
        if ($this->settings->value('allow_live_execution') === true) {
            throw ValidationException::withMessages(['allow_live_execution' => 'LIVE flag must remain false.']);
        }
        if ($this->settings->value('auto_demo_execution') !== true || $this->settings->value('allow_demo_execution') !== true) {
            throw ValidationException::withMessages(['auto_demo' => 'AUTO DEMO settings required.']);
        }

        $account = $session->brokerAccount;
        if (! $account) {
            throw ValidationException::withMessages(['account' => 'No broker account bound to session.']);
        }
        $verified = $this->verifyAccount($account, true);
        if (! ($verified['ok'] ?? false)) {
            $this->enterSafeMode($session, $verified['blocker'] ?? 'ACCOUNT_UNSAFE');
            throw ValidationException::withMessages(['account' => $verified['blocker'] ?? 'Account unsafe']);
        }

        $blocking = $this->locks->blocksEntries($session);
        if ($blocking) {
            throw ValidationException::withMessages([
                'lock' => 'Blocked by '.$blocking->lock_type->value.': '.$blocking->reason,
            ]);
        }
    }

    /**
     * @return array{ok:bool,blocker?:string,trade_mode?:string,login?:string,server?:string}
     */
    public function verifyAccount(BrokerAccount $account, bool $refresh): array
    {
        if ($account->environment === TradingEnvironment::Live) {
            return ['ok' => false, 'blocker' => 'LIVE_ACCOUNT_DETECTED', 'trade_mode' => 'LIVE'];
        }
        if ($account->environment !== TradingEnvironment::Demo) {
            return ['ok' => false, 'blocker' => 'NON_DEMO_ACCOUNT', 'trade_mode' => $account->environment->value];
        }

        try {
            $snap = $this->verifier->verify($account, $refresh);
            $mode = (string) ($snap['trade_mode'] ?? 'UNKNOWN');
            if (AutomationSafety::isLiveLike($mode) || $mode !== AccountTradeMode::Demo->value) {
                return ['ok' => false, 'blocker' => 'NON_DEMO_TRADE_MODE_'.$mode, 'trade_mode' => $mode];
            }

            return [
                'ok' => true,
                'trade_mode' => AccountTradeMode::Demo->value,
                'login' => (string) ($snap['login'] ?? ''),
                'server' => (string) ($snap['server'] ?? ''),
                'verified_at' => $snap['verified_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'blocker' => 'VERIFICATION_FAILED', 'trade_mode' => 'UNVERIFIED', 'detail' => $e->getMessage()];
        }
    }

    public function enterSafeMode(AutomationSession $session, string $reason): void
    {
        $session->forceFill([
            'state' => AutomationState::SafeMode,
            'safe_mode' => true,
            'entries_blocked' => true,
            'auto_entry_paused' => true,
            'safe_mode_reason' => $reason,
            'pause_reason' => $reason,
        ])->save();

        $this->locks->acquire(
            $session->user,
            $session,
            AutomationLockType::SafeMode,
            $reason,
        );

        $this->events->record($session->user, $session, 'SAFE_MODE_ENTERED', [
            'reason' => $reason,
            'note' => 'No LIVE cleanup attempted. Entries blocked. Explicit resume required.',
        ], 'CRITICAL');
    }

    private function heartbeatFresh(string $service): bool
    {
        $hb = ServiceHeartbeat::query()->where('service', $service)->latest('observed_at')->first();

        return $hb !== null && $hb->observed_at !== null && $hb->observed_at->gt(now()->subMinutes(15));
    }
}
