<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\GuardsStateTransitions;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends BaseModel
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
            'type' => OrderType::class,
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'environment' => TradingEnvironment::class,
            'simulated' => 'boolean',
            'broker_transmitted' => 'boolean',
            'requested_volume' => 'decimal:4',
            'filled_volume' => 'decimal:4',
            'remaining_volume' => 'decimal:4',
            'requested_price' => 'decimal:8',
            'average_fill_price' => 'decimal:8',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'filled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'requested_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expired_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-ORD-';
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->correlation_id ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function executionCommand(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function riskEvents(): HasMany
    {
        return $this->hasMany(RiskEvent::class);
    }

    public function position(): HasOne
    {
        return $this->hasOne(Position::class, 'opening_order_id');
    }
}
