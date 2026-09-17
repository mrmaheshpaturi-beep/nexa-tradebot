<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyVersion extends BaseModel
{
    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
