<?php

namespace App\Models;

use App\Enums\ProposedPlanStatus;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ProposedPlan extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => ProposedPlanStatus::class,
            'proposed_volume' => 'decimal:4',
            'proposed_risk_amount' => 'decimal:4',
            'proposed_risk_percent' => 'decimal:4',
            'proposed_entry' => 'decimal:8',
            'proposed_stop_loss' => 'decimal:8',
            'proposed_take_profit' => 'decimal:8',
            'proposed_reward_risk' => 'decimal:4',
            'proposed_margin' => 'decimal:4',
            'sizing_breakdown' => 'array',
            'symbol_specs' => 'array',
            'broker_routable' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(function (self $plan): void {
            // Only status transitions allowed after creation (proposal immutability of sizing facts).
            $dirty = array_keys($plan->getDirty());
            $allowed = ['status', 'updated_at'];
            if (array_diff($dirty, $allowed) !== []) {
                throw new RuntimeException('ProposedPlan sizing facts are immutable after creation.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('ProposedPlan records cannot be deleted.');
        });
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-PLAN-';
    }

    public function riskDecision(): BelongsTo
    {
        return $this->belongsTo(RiskDecision::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
