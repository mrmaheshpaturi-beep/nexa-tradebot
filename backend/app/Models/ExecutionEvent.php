<?php

namespace App\Models;

use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable execution timeline event.
 */
class ExecutionEvent extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'EXE-EVT-';
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('ExecutionEvent records are immutable.');
        });
        static::deleting(function (): void {
            throw new DomainException('ExecutionEvent records are immutable.');
        });
    }

    public function executionCommand(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
