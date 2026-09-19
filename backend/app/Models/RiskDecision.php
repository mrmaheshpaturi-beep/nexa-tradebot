<?php

namespace App\Models;

use App\Enums\RiskDecisionStatus;
use App\Enums\RiskReasonCode;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class RiskDecision extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => RiskDecisionStatus::class,
            'decision' => RiskDecisionStatus::class,
            'reason_code' => RiskReasonCode::class,
            'checks' => 'array',
            'rule_results' => 'array',
            'account_context' => 'array',
            'symbol_context' => 'array',
            'evaluated_at' => 'datetime',
            'risk_amount' => 'decimal:4',
            'requested_risk' => 'decimal:4',
            'approved_risk' => 'decimal:4',
            'requested_volume' => 'decimal:4',
            'approved_volume' => 'decimal:4',
            'reward_risk' => 'decimal:4',
            'immutable' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(function (self $decision): void {
            if ($decision->getOriginal('immutable') !== false) {
                throw new RuntimeException('RiskDecision records are immutable after creation.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('RiskDecision records cannot be deleted.');
        });
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-RISK-';
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function proposedPlan(): HasOne
    {
        return $this->hasOne(ProposedPlan::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(RiskReservation::class);
    }
}
