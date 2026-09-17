<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategySetting extends BaseModel
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }
}
