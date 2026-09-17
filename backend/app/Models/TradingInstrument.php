<?php

namespace App\Models;

use App\Enums\TradingAssetClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingInstrument extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'asset_class' => TradingAssetClass::class,
            'point_size' => 'decimal:10',
            'contract_size' => 'decimal:4',
            'volume_min' => 'decimal:4',
            'volume_max' => 'decimal:4',
            'volume_step' => 'decimal:4',
            'margin_rate' => 'decimal:8',
            'tick_size' => 'decimal:10',
            'tick_value' => 'decimal:8',
            'minimum_volume' => 'decimal:4',
            'maximum_volume' => 'decimal:4',
            'step_volume' => 'decimal:4',
            'minimum_stop_distance' => 'decimal:10',
        ];
    }

    public function intents(): HasMany
    {
        return $this->hasMany(TradeIntent::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
