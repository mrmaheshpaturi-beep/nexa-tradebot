<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\TimeInForce;
use App\Enums\TradeIntentStatus;
use App\Enums\TradeOrigin;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\GuardsStateTransitions;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeIntent extends BaseModel
{
    use GuardsStateTransitions, HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'origin' => TradeOrigin::class,
            'side' => OrderDirection::class,
            'order_type' => OrderType::class,
            'time_in_force' => TimeInForce::class,
            'status' => TradeIntentStatus::class,
            'environment' => TradingEnvironment::class,
            'metadata' => 'array',
            'volume' => 'decimal:4',
            'requested_volume' => 'decimal:4',
            'requested_price' => 'decimal:8',
            'requested_entry' => 'decimal:8',
            'stop_loss' => 'decimal:8',
            'take_profit' => 'decimal:8',
            'take_profit_2' => 'decimal:8',
            'risk_percent' => 'decimal:4',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-INT-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }

    public function riskDecision(): HasOne
    {
        return $this->hasOne(RiskDecision::class);
    }

    public function proposedPlan(): HasOne
    {
        return $this->hasOne(ProposedPlan::class);
    }

    public function executionCommand(): HasOne
    {
        return $this->hasOne(ExecutionCommand::class);
    }
}
