<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyChangeRequest extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'proposed_change' => 'array',
            'rationale' => 'array',
            'requires_human_approval' => 'boolean',
            'ai_may_apply' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-CR-';
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
