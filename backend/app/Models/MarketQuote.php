<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MarketQuote extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'bid' => 'decimal:8',
            'ask' => 'decimal:8',
            'spread' => 'decimal:8',
            'last' => 'decimal:8',
            'volume' => 'decimal:4',
            'age_seconds' => 'decimal:3',
            'is_stale' => 'boolean',
            'usable' => 'boolean',
            'quality_issues' => 'array',
            'payload' => 'array',
            'source_timestamp' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
