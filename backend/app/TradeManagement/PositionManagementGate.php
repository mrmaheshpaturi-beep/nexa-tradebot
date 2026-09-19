<?php

namespace App\TradeManagement;

use App\Enums\AccountTradeMode;
use App\Enums\BrokerActionLockScope;
use App\Enums\ManagementStatus;
use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Execution\DemoAccountVerifier;
use App\Models\BrokerActionLock;
use App\Models\BrokerAccount;
use App\Models\ManagedPosition;
use App\Models\RiskLock;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Defense-in-depth DEMO gate before every broker-changing management action.
 */
class PositionManagementGate
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DemoAccountVerifier $verifier,
        private readonly PositionOwnershipService $ownership,
    ) {}

    /**
     * @return array<string,mixed> verification snapshot
     */
    public function assertCanManage(
        ManagedPosition $position,
        bool $protectiveClose = false,
        bool $forceFreshVerify = true,
    ): array {
        $this->ownership->assertNexaManaged($position);

        if ($position->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages(['environment' => 'LIVE/non-DEMO management hard-blocked.']);
        }

        if (in_array($position->management_status, [
            ManagementStatus::ForeignIgnored,
            ManagementStatus::Orphaned,
            ManagementStatus::Blocked,
        ], true)) {
            throw ValidationException::withMessages(['status' => 'Position management status blocks broker actions.']);
        }

        if ($position->auto_management_paused && ! $protectiveClose) {
            throw ValidationException::withMessages(['paused' => 'Auto management is paused for this position.']);
        }

        /** @var BrokerAccount $account */
        $account = $position->brokerAccount;
        if ($account->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages(['account' => 'Broker account is not DEMO.']);
        }
        if (! $account->is_enabled) {
            throw ValidationException::withMessages(['account' => 'DEMO account disabled.']);
        }

        if ($this->settings->value('allow_live_execution') === true) {
            throw ValidationException::withMessages(['allow_live_execution' => 'LIVE flag must remain false.']);
        }
        if ($this->settings->value('allow_demo_execution') !== true) {
            throw ValidationException::withMessages(['allow_demo_execution' => 'DEMO execution/management disabled.']);
        }

        // Emergency stop blocks non-protective management; protective emergency exits still allowed.
        if ($this->settings->value('emergency_stop') !== false && ! $protectiveClose) {
            throw ValidationException::withMessages(['emergency_stop' => 'Emergency stop is active.']);
        }

        $this->assertBrokerActionLocks($account, $protectiveClose);
        $this->assertRiskLocksAllow($position, $protectiveClose);

        $verification = $this->verifier->verify($account, $forceFreshVerify);
        $mode = AccountTradeMode::fromBridge($verification['trade_mode'] ?? $account->verified_trade_mode);
        if ($mode === AccountTradeMode::Live) {
            throw ValidationException::withMessages(['trade_mode' => 'LIVE trade mode hard-fails at PositionManagementGate.']);
        }
        if ($mode !== AccountTradeMode::Demo) {
            throw ValidationException::withMessages(['trade_mode' => 'UNKNOWN/AMBIGUOUS trade mode hard-fails management.']);
        }

        if (($verification['account_id'] ?? $account->broker_login) != $account->broker_login) {
            throw ValidationException::withMessages(['account_switch' => 'Connected login mismatch — management blocked.']);
        }

        return $verification;
    }

    private function assertBrokerActionLocks(BrokerAccount $account, bool $protectiveClose): void
    {
        $all = BrokerActionLock::query()
            ->where('is_active', true)
            ->where('scope', BrokerActionLockScope::BlockAllBrokerActions->value)
            ->where(function ($q) use ($account): void {
                $q->whereNull('broker_account_id')->orWhere('broker_account_id', $account->id);
            })
            ->exists();
        if ($all) {
            throw ValidationException::withMessages([
                'broker_action_lock' => 'BLOCK_ALL_BROKER_ACTIONS is active.',
            ]);
        }

        if (! $protectiveClose) {
            $entries = BrokerActionLock::query()
                ->where('is_active', true)
                ->where('scope', BrokerActionLockScope::BlockNewEntries->value)
                ->where(function ($q) use ($account): void {
                    $q->whereNull('broker_account_id')->orWhere('broker_account_id', $account->id);
                })
                ->exists();
            // BLOCK_NEW_ENTRIES does not block protective management of existing positions.
            unset($entries);
        }
    }

    private function assertRiskLocksAllow(ManagedPosition $position, bool $protectiveClose): void
    {
        $locks = RiskLock::query()
            ->where('is_active', true)
            ->where(function ($q) use ($position): void {
                $q->where('user_id', $position->user_id)
                    ->orWhere('broker_account_id', $position->broker_account_id);
            })
            ->get();

        foreach ($locks as $lock) {
            $blocksProtective = (bool) ($lock->blocks_protective_closes ?? false);
            $blocksNew = (bool) ($lock->blocks_new_entries ?? true);
            if ($protectiveClose) {
                if ($blocksProtective) {
                    throw ValidationException::withMessages([
                        'risk_lock' => 'RiskLock explicitly blocks protective closes.',
                    ]);
                }
                // Normal risk locks block NEW entries only — allow protective close.
                continue;
            }
            if ($blocksNew && ! $protectiveClose) {
                // Non-protective management (e.g. discretionary trail tighten) still allowed for existing;
                // only brand-new entries are blocked by RiskEngine. No-op here.
                continue;
            }
        }
    }
}
