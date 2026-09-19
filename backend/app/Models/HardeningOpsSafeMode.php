<?php

namespace App\Models;

class HardeningOpsSafeMode extends BaseModel
{
    protected $table = 'hardening_ops_safe_modes';

    protected $fillable = [
        'public_id', 'scope', 'scope_ref', 'active', 'reason',
        'activated_by', 'activated_at', 'cleared_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'activated_at' => 'datetime',
            'cleared_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
