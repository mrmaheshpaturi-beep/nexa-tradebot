<?php

namespace App\TradeManagement;

use App\Models\ManagedPosition;
use App\Models\PositionManagementLock;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagementActionLockService
{
    public function acquire(User $user, ManagedPosition $position): PositionManagementLock
    {
        $key = 'mgmt:'.$position->id;
        $existing = PositionManagementLock::query()->where('lock_key', $key)->where('status', 'HELD')->first();
        if ($existing && $existing->expires_at->isFuture()) {
            throw ValidationException::withMessages(['lock' => 'Concurrent management action blocked for position.']);
        }
        if ($existing) {
            $existing->forceFill(['status' => 'EXPIRED', 'released_at' => now()])->save();
        }

        return PositionManagementLock::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $user->id,
            'lock_key' => $key,
            'status' => 'HELD',
            'owner_token' => (string) Str::uuid(),
            'acquired_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ]);
    }

    public function release(PositionManagementLock $lock): void
    {
        $lock->forceFill(['status' => 'RELEASED', 'released_at' => now()])->save();
    }
}
