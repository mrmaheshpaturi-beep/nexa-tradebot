<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MarketCandle extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'open' => 'decimal:8',
            'high' => 'decimal:8',
            'low' => 'decimal:8',
            'close' => 'decimal:8',
            'usable' => 'boolean',
            'quality_issues' => 'array',
            'payload' => 'array',
            'open_time' => 'datetime',
            'close_time' => 'datetime',
        ];
    }
}
