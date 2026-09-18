<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyPerformanceStat extends BaseModel
{
    protected function casts(): array
    {
        return [
            'by_direction' => 'array',
            'avg_score' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }
}
