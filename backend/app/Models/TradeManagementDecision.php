<?php

namespace App\Models;

use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementDecisionType;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeManagementDecision extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'decision_type' => ManagementDecisionType::class,
            'status' => ManagementDecisionStatus::class,
            'proposed_sl' => 'decimal:8',
            'proposed_tp' => 'decimal:8',
            'proposed_close_volume' => 'decimal:4',
            'market_snapshot' => 'array',
            'risk_snapshot' => 'array',
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TMDEC-';
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PositionManagementAction::class, 'decision_id');
    }
}
