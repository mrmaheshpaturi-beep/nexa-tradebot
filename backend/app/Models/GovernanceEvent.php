<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceEvent extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'immutable' => 'boolean',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(function (self $model): void {
            if ($model->immutable || $model->getOriginal('immutable')) {
                throw new \RuntimeException('Governance events are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \RuntimeException('Governance events cannot be deleted.');
        });
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-EVNT-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GovernedStrategyVersion::class, 'governed_strategy_version_id');
    }
}
