<?php

namespace App\Models;

use App\Enums\ExecutionOutcome;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable once created — updates/deletes are rejected.
 */
class ExecutionResult extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'outcome' => ExecutionOutcome::class,
            'requested_volume' => 'decimal:4',
            'filled_volume' => 'decimal:4',
            'fill_price' => 'decimal:8',
            'partial_fill' => 'boolean',
            'request_snapshot' => 'array',
            'response_snapshot' => 'array',
            'verification_snapshot' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'EXE-RES-';
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('ExecutionResult records are immutable.');
        });
        static::deleting(function (): void {
            throw new DomainException('ExecutionResult records are immutable.');
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
