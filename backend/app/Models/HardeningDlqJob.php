<?php

namespace App\Models;

class HardeningDlqJob extends BaseModel
{
    protected $table = 'hardening_dlq_jobs';

    protected $fillable = [
        'public_id', 'source_job_id', 'queue_name', 'job_type', 'idempotency_key',
        'payload', 'error', 'status', 'dead_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'dead_at' => 'datetime',
        ];
    }
}
