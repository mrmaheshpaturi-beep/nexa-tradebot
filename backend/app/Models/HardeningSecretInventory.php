<?php

namespace App\Models;

class HardeningSecretInventory extends BaseModel
{
    protected $table = 'hardening_secret_inventory';

    protected $fillable = [
        'public_id', 'secret_key', 'category', 'provider', 'storage',
        'frontend_forbidden', 'git_forbidden', 'last_rotated_at', 'rotation_due_at',
        'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'frontend_forbidden' => 'boolean',
            'git_forbidden' => 'boolean',
            'last_rotated_at' => 'datetime',
            'rotation_due_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
