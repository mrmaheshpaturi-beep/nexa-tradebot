<?php

namespace App\Models;

use App\Enums\PositionManagementActionStatus;
use App\Enums\PositionManagementActionType;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionManagementAction extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'action_type' => PositionManagementActionType::class,
            'status' => PositionManagementActionStatus::class,
            'requested_sl' => 'decimal:8',
            'requested_tp' => 'decimal:8',
            'requested_close_volume' => 'decimal:4',
            'order_check_passed' => 'boolean',
            'blind_retry_forbidden' => 'boolean',
            'request_snapshot' => 'array',
            'response_snapshot' => 'array',
            'verification_snapshot' => 'array',
            'created_at_action' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'PMACT-';
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(TradeManagementDecision::class, 'decision_id');
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
