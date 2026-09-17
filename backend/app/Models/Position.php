<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\PositionStatus;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\GuardsStateTransitions;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends BaseModel
{
    use GuardsStateTransitions, HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'direction' => OrderDirection::class,
            'side' => OrderDirection::class,
            'environment' => TradingEnvironment::class,
            'status' => PositionStatus::class,
            'initial_volume' => 'decimal:4',
            'current_volume' => 'decimal:4',
            'average_entry_price' => 'decimal:8',
            'current_price' => 'decimal:8',
            'stop_loss' => 'decimal:8',
            'take_profit' => 'decimal:8',
            'realized_pnl' => 'decimal:4',
            'unrealized_pnl' => 'decimal:4',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-POS-';
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function openingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'opening_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PositionEvent::class);
    }
}
