<?php

namespace App\Models;

class HardeningMfaChallenge extends BaseModel
{
    protected $table = 'hardening_mfa_challenges';

    protected $fillable = [
        'public_id', 'user_id', 'purpose', 'challenge_hash', 'status',
        'expires_at', 'consumed_at', 'nonce',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
