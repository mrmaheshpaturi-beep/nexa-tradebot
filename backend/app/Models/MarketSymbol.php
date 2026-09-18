<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MarketSymbol extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'quality_issues' => 'array',
            'payload' => 'array',
            'usable' => 'boolean',
            'observed_at' => 'datetime',
            'point' => 'decimal:10',
            'trade_tick_size' => 'decimal:10',
            'trade_tick_value' => 'decimal:8',
            'volume_min' => 'decimal:4',
            'volume_max' => 'decimal:4',
            'volume_step' => 'decimal:4',
        ];
    }
}
