<?php

namespace App\Models;

class HardeningQueueJob extends BaseModel
{
    protected $table = 'hardening_queue_jobs';

    protected $fillable = [
        'public_id', 'queue_name', 'priority', 'job_type', 'idempotency_key',
        'status', 'payload', 'attempts', 'max_attempts', 'error',
        'available_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
