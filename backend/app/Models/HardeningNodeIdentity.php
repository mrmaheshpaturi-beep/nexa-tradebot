<?php

namespace App\Models;

class HardeningNodeIdentity extends BaseModel
{
    protected $table = 'hardening_node_identities';

    protected $fillable = [
        'public_id', 'node_label', 'node_fingerprint', 'platform', 'status',
        'time_sync_ok', 'tls_required', 'hardening_checklist', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'time_sync_ok' => 'boolean',
            'tls_required' => 'boolean',
            'hardening_checklist' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
