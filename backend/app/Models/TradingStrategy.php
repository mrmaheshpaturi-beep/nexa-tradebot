<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingStrategy extends BaseModel
{
    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'timeframes' => 'array',
            'sessions' => 'array',
            'parameters' => 'array',
            'higher_timeframes' => 'array',
            'enabled' => 'boolean',
            'auto_trading_enabled' => 'boolean',
            'auto_simulation' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(StrategySetting::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StrategyVersion::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function tradeIntents(): HasMany
    {
        return $this->hasMany(TradeIntent::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
