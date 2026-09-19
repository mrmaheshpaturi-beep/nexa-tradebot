<?php

namespace App\Models;

class HardeningDeployVersion extends BaseModel
{
    protected $table = 'hardening_deploy_versions';

    protected $fillable = [
        'public_id', 'version', 'git_sha', 'status', 'maintenance_mode',
        'trading_paused', 'reconcile_before_resume', 'checklist', 'rollback_of',
        'registered_by', 'activated_at', 'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_mode' => 'boolean',
            'trading_paused' => 'boolean',
            'reconcile_before_resume' => 'boolean',
            'checklist' => 'array',
            'rollback_of' => 'array',
            'activated_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }
}
