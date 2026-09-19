<?php

namespace App\Models;

class HardeningReplayNonce extends BaseModel
{
    protected $table = 'hardening_replay_nonces';

    protected $fillable = [
        'nonce', 'purpose', 'user_id', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
