<?php

namespace App\Services;

use App\Enums\RiskLockType;
use App\Enums\RiskReasonCode;
use App\Models\BrokerAccount;
use App\Models\RiskLock;
use App\Models\RiskProfile;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RiskLockService
{
    /**
     * @return list<string>
     */
    public function activeLockCodes(?BrokerAccount $account, ?User $user = null): array
    {
        $query = RiskLock::query()->where('is_active', true);
        if ($user) {
            $query->where('user_id', $user->id);
        } elseif ($account) {
            $query->where('user_id', $account->user_id);
        }
        if ($account) {
            $query->where(function ($q) use ($account): void {
                $q->whereNull('broker_account_id')->orWhere('broker_account_id', $account->id);
            });
        }

        return $query->orderBy('id')->get()
            ->map(fn (RiskLock $lock) => $lock->lock_type->value.':'.$lock->reason_code->value)
            ->values()
            ->all();
    }

    /**
     * Create a lock if an equivalent active lock does not already exist (anti-spam).
     */
    public function ensureLock(
        User $user,
        RiskLockType $type,
        RiskReasonCode $reason,
        string $message,
        ?BrokerAccount $account = null,
        ?RiskProfile $profile = null,
        array $context = [],
        ?User $actor = null,
    ): RiskLock {
        return DB::transaction(function () use ($user, $type, $reason, $message, $account, $profile, $context, $actor): RiskLock {
            $existing = RiskLock::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where('lock_type', $type->value)
                ->where('reason_code', $reason->value)
                ->when($account, fn ($q) => $q->where('broker_account_id', $account->id))
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $lock = RiskLock::query()->create([
                'user_id' => $user->id,
                'broker_account_id' => $account?->id,
                'risk_profile_id' => $profile?->id,
                'lock_type' => $type,
                'reason_code' => $reason,
                'message' => $message,
                'is_active' => true,
                'context' => $context,
                'locked_at' => now(),
                'created_by' => $actor?->id ?? $user->id,
            ]);

            SystemEvent::query()->create([
                'level' => 'WARNING',
                'category' => 'RISK',
                'message' => 'risk.lock.created: '.$message,
                'context' => [
                    'event' => 'risk.lock.created',
                    'lock_public_id' => $lock->public_id,
                    'lock_type' => $type->value,
                    'reason_code' => $reason->value,
                    'account_public_id' => $account?->public_id,
                ],
                'occurred_at' => now(),
            ]);

            return $lock;
        });
    }

    public function release(RiskLock $lock, User $actor, string $note = 'Lock released'): RiskLock
    {
        if (! $lock->is_active) {
            return $lock;
        }

        $lock->update([
            'is_active' => false,
            'released_at' => now(),
            'released_by' => $actor->id,
            'context' => array_merge($lock->context ?? [], ['release_note' => $note]),
        ]);

        SystemEvent::query()->create([
            'level' => 'INFO',
            'category' => 'RISK',
            'message' => 'risk.lock.released: '.$note,
            'context' => [
                'event' => 'risk.lock.released',
                'lock_public_id' => $lock->public_id,
                'lock_type' => $lock->lock_type->value,
                'reason_code' => $lock->reason_code->value,
            ],
            'occurred_at' => now(),
        ]);

        return $lock->fresh();
    }

    public function maybeAutoLockFromReason(
        User $user,
        BrokerAccount $account,
        RiskProfile $profile,
        RiskReasonCode $reason,
        string $message,
        array $context = [],
    ): void {
        $map = [
            RiskReasonCode::DailyLossLimit->value => RiskLockType::DailyLoss,
            RiskReasonCode::WeeklyLossLimit->value => RiskLockType::WeeklyLoss,
            RiskReasonCode::DrawdownLimit->value => RiskLockType::Drawdown,
            RiskReasonCode::ConsecutiveLossLimit->value => RiskLockType::LossStreak,
            RiskReasonCode::MarginLimit->value => RiskLockType::Margin,
            RiskReasonCode::MaxExposure->value => RiskLockType::Exposure,
            RiskReasonCode::EmergencyStop->value => RiskLockType::Emergency,
        ];
        $type = $map[$reason->value] ?? null;
        if ($type === null) {
            return;
        }
        $this->ensureLock($user, $type, $reason, $message, $account, $profile, $context);
    }
}
