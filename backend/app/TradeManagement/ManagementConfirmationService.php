<?php

namespace App\TradeManagement;

use App\Enums\PositionManagementActionType;
use App\Models\ManagedPosition;
use App\Models\ManagementConfirmation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagementConfirmationService
{
    /**
     * @param  array<string,mixed>  $preview
     * @return array{confirmation:ManagementConfirmation,challenge_token:string}
     */
    public function prepare(User $user, ManagedPosition $position, PositionManagementActionType $type, string $idempotencyKey, array $preview): array
    {
        $existing = ManagementConfirmation::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return ['confirmation' => $existing, 'challenge_token' => ''];
        }

        $challenge = Str::random(48);
        $confirmation = ManagementConfirmation::query()->create([
            'user_id' => $user->id,
            'managed_position_id' => $position->id,
            'action_type' => $type,
            'status' => 'STEP1',
            'step' => 1,
            'challenge_token_hash' => hash('sha256', $challenge),
            'idempotency_key' => $idempotencyKey,
            'preview_payload' => $preview,
            'step1_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        return ['confirmation' => $confirmation, 'challenge_token' => $challenge];
    }

    /**
     * @return array{confirmation:ManagementConfirmation,confirm_token:string}
     */
    public function confirm(ManagementConfirmation $confirmation, string $challengeToken): array
    {
        if ($confirmation->expires_at?->isPast()) {
            throw ValidationException::withMessages(['confirmation' => 'Management confirmation expired.']);
        }
        if (! hash_equals((string) $confirmation->challenge_token_hash, hash('sha256', $challengeToken))) {
            throw ValidationException::withMessages(['challenge_token' => 'Invalid challenge token.']);
        }
        $confirm = Str::random(48);
        $confirmation->forceFill([
            'status' => 'READY',
            'step' => 2,
            'confirm_token_hash' => hash('sha256', $confirm),
            'step2_at' => now(),
        ])->save();

        return ['confirmation' => $confirmation->fresh(), 'confirm_token' => $confirm];
    }

    public function assertConsumable(ManagementConfirmation $confirmation, string $confirmToken): void
    {
        if ($confirmation->status !== 'READY' || $confirmation->expires_at?->isPast()) {
            throw ValidationException::withMessages(['confirmation' => 'Confirmation not ready or expired.']);
        }
        if (! hash_equals((string) $confirmation->confirm_token_hash, hash('sha256', $confirmToken))) {
            throw ValidationException::withMessages(['confirm_token' => 'Invalid confirm token.']);
        }
    }
}
