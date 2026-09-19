<?php

namespace App\Automation;

use App\Enums\AutomationLockType;
use App\Models\AutomationLock;
use App\Models\AutomationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AutomationExecutionLockService
{
    public function __construct(private readonly AutomationEventRecorder $events) {}

    /**
     * @param  array<string,mixed>  $meta
     */
    public function acquire(
        User $user,
        ?AutomationSession $session,
        AutomationLockType $type,
        string $reason,
        ?string $scopeKey = null,
        ?int $ttlSeconds = null,
        array $meta = [],
    ): AutomationLock {
        return DB::transaction(function () use ($user, $session, $type, $reason, $scopeKey, $ttlSeconds, $meta): AutomationLock {
            $lock = AutomationLock::query()->create([
                'user_id' => $user->id,
                'automation_session_id' => $session?->id,
                'lock_type' => $type,
                'scope_key' => $scopeKey,
                'precedence' => $type->precedence(),
                'reason' => $reason,
                'active' => true,
                'expires_at' => $ttlSeconds ? now()->addSeconds($ttlSeconds) : null,
                'meta' => $meta,
            ]);
            if ($session) {
                $this->events->record($user, $session, 'LOCK_ACQUIRED', [
                    'lock_type' => $type->value,
                    'reason' => $reason,
                    'scope_key' => $scopeKey,
                ], 'WARN');
            }

            return $lock;
        });
    }

    public function release(AutomationLock $lock, User $user): void
    {
        if (! $lock->active) {
            return;
        }
        $lock->forceFill(['active' => false, 'released_at' => now()])->save();
        if ($lock->session) {
            $this->events->record($user, $lock->session, 'LOCK_RELEASED', [
                'lock_type' => $lock->lock_type->value,
                'lock' => $lock->public_id,
            ]);
        }
    }

    public function expireStale(): int
    {
        return AutomationLock::query()
            ->where('active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['active' => false, 'released_at' => now()]);
    }

    /**
     * @return list<AutomationLock>
     */
    public function activeBlocking(AutomationSession $session): array
    {
        $this->expireStale();

        return AutomationLock::query()
            ->where('user_id', $session->user_id)
            ->where('active', true)
            ->where(function ($q) use ($session): void {
                $q->where('automation_session_id', $session->id)
                    ->orWhereNull('automation_session_id');
            })
            ->orderBy('precedence')
            ->get()
            ->all();
    }

    public function blocksEntries(AutomationSession $session): ?AutomationLock
    {
        foreach ($this->activeBlocking($session) as $lock) {
            if (in_array($lock->lock_type, [
                AutomationLockType::Kill,
                AutomationLockType::SafeMode,
                AutomationLockType::DailyLoss,
                AutomationLockType::Drawdown,
                AutomationLockType::LossStreak,
                AutomationLockType::Entry,
                AutomationLockType::Session,
            ], true)) {
                return $lock;
            }
        }

        return null;
    }

    public function blocksSymbol(AutomationSession $session, string $symbol): ?AutomationLock
    {
        foreach ($this->activeBlocking($session) as $lock) {
            if ($lock->lock_type === AutomationLockType::Symbol
                && strcasecmp((string) $lock->scope_key, $symbol) === 0) {
                return $lock;
            }
        }

        return null;
    }
}
