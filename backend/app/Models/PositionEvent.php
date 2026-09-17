<?php

namespace App\Models;

use App\Enums\PositionEventType;
use App\Enums\TradeOrigin;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionEvent extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'type' => PositionEventType::class,
            'origin' => TradeOrigin::class,
            'changes' => 'array',
            'previous_state' => 'array',
            'new_state' => 'array',
            'occurred_at' => 'datetime',
            'volume_before' => 'decimal:4',
            'volume_after' => 'decimal:4',
            'price' => 'decimal:8',
            'realized_pnl' => 'decimal:4',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-EVT-';
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function executionCommand(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class);
    }
}
