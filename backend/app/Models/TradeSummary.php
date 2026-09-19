<?php

namespace App\Models;

use App\Enums\CloseReason;
use App\Enums\OrderDirection;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TradeSummary extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'direction' => OrderDirection::class,
            'close_reason' => CloseReason::class,
            'entry_price' => 'decimal:8',
            'exit_price' => 'decimal:8',
            'initial_volume' => 'decimal:4',
            'closed_volume' => 'decimal:4',
            'realized_pnl' => 'decimal:4',
            'mae' => 'decimal:8',
            'mfe' => 'decimal:8',
            'r_multiple' => 'decimal:8',
            'break_even_applied' => 'boolean',
            'trailing_used' => 'boolean',
            'timeline' => 'array',
            'finalized' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TSUM-';
    }

    protected static function booted(): void
    {
        static::updating(function (TradeSummary $summary): void {
            if ($summary->getOriginal('finalized') && $summary->finalized) {
                throw ValidationException::withMessages([
                    'trade_summary' => 'TradeSummary is immutable after finalize.',
                ]);
            }
        });
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
