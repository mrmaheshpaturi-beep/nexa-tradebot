<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeManagementEvent extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TMEVT-';
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(TradeManagementDecision::class, 'decision_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(PositionManagementAction::class, 'action_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
