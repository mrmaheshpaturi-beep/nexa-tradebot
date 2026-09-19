<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionSubmissionLock extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'EXE-LCK-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function executionCommand(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class);
    }

    public function isHeld(): bool
    {
        return $this->status === 'HELD' && $this->expires_at?->isFuture();
    }
}
