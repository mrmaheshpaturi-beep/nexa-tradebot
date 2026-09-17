<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signal extends BaseModel
{
    protected function casts(): array
    {
        return [
            'direction' => OrderDirection::class,
            'environment' => TradingEnvironment::class,
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
