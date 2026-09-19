<?php

namespace App\Models;

class HardeningIsolatedRestore extends BaseModel
{
    protected $table = 'hardening_isolated_restores';

    protected $fillable = [
        'public_id', 'source_backup_path', 'status', 'isolated', 'verified',
        'reconcile_required_before_trading', 'auto_resume_forbidden',
        'manifest', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'isolated' => 'boolean',
            'verified' => 'boolean',
            'reconcile_required_before_trading' => 'boolean',
            'auto_resume_forbidden' => 'boolean',
            'manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
