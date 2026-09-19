<?php

namespace App\Models;

class HardeningWorkerProcess extends BaseModel
{
    protected $table = 'hardening_worker_processes';

    protected $fillable = [
        'public_id', 'worker_kind', 'label', 'status', 'graceful_shutdown',
        'restart_reconcile_required', 'reconcile_completed', 'started_at',
        'stopped_at', 'last_heartbeat_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'graceful_shutdown' => 'boolean',
            'restart_reconcile_required' => 'boolean',
            'reconcile_completed' => 'boolean',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
