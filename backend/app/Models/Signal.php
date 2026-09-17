<?php

namespace App\Models;

use App\Enums\SignalDirection;
use App\Enums\SignalSource;
use App\Enums\SignalStatus;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Signal extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'direction' => SignalDirection::class,
            'status' => SignalStatus::class,
            'source' => SignalSource::class,
            'environment' => TradingEnvironment::class,
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'metadata' => 'array',
            'entry_reference' => 'decimal:8',
            'take_profit_1_reference' => 'decimal:8',
            'take_profit_2_reference' => 'decimal:8',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-SIG-';
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function intent(): HasOne
    {
        return $this->hasOne(TradeIntent::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
