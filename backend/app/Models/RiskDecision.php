<?php

namespace App\Models;

use App\Enums\RiskDecisionStatus;
use App\Enums\RiskReasonCode;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskDecision extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'status' => RiskDecisionStatus::class,
            'decision' => RiskDecisionStatus::class,
            'reason_code' => RiskReasonCode::class,
            'checks' => 'array',
            'evaluated_at' => 'datetime',
            'risk_amount' => 'decimal:4',
            'requested_risk' => 'decimal:4',
            'approved_risk' => 'decimal:4',
            'requested_volume' => 'decimal:4',
            'approved_volume' => 'decimal:4',
            'reward_risk' => 'decimal:4',
        ];
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
}
