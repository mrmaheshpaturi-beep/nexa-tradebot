<?php

namespace App\Execution;

use App\Models\ExecutionCommand;
use App\Models\ExecutionSubmissionLock;
use App\Models\TradeIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecutionSubmissionLockService
{
    public function acquire(User $user, TradeIntent $intent, ?ExecutionCommand $command = null, int $ttlSeconds = 60): ExecutionSubmissionLock
    {
        $lockKey = 'intent:'.$intent->id.':submit';

        return DB::transaction(function () use ($user, $intent, $command, $ttlSeconds, $lockKey): ExecutionSubmissionLock {
            $this->expireDue();
            $existing = ExecutionSubmissionLock::query()
                ->where('lock_key', $lockKey)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isHeld()) {
                throw ValidationException::withMessages([
                    'lock' => 'An execution submission lock is already held for this intent.',
                ]);
            }

            if ($existing) {
                $existing->update([
                    'user_id' => $user->id,
                    'trade_intent_id' => $intent->id,
                    'execution_command_id' => $command?->id,
                    'status' => 'HELD',
                    'owner_token' => Str::random(40),
                    'acquired_at' => now(),
                    'expires_at' => now()->addSeconds($ttlSeconds),
                    'released_at' => null,
                ]);

                return $existing->fresh();
            }

            return ExecutionSubmissionLock::query()->create([
                'user_id' => $user->id,
                'trade_intent_id' => $intent->id,
                'execution_command_id' => $command?->id,
                'lock_key' => $lockKey,
                'status' => 'HELD',
                'owner_token' => Str::random(40),
                'acquired_at' => now(),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);
        });
    }

    public function release(ExecutionSubmissionLock $lock): ExecutionSubmissionLock
    {
        if ($lock->status !== 'HELD') {
            return $lock;
        }
        $lock->update([
            'status' => 'RELEASED',
            'released_at' => now(),
        ]);

        return $lock->fresh();
    }

    public function expireDue(): int
    {
        return ExecutionSubmissionLock::query()
            ->where('status', 'HELD')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'EXPIRED',
                'released_at' => now(),
            ]);
    }
}
