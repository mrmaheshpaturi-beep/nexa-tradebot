<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyDeployment extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'profile_snapshot' => 'array',
            'positions_preserved' => 'boolean',
            'history_preserved' => 'boolean',
            'deployed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'retired_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-DP-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GovernedStrategyVersion::class, 'governed_strategy_version_id');
    }

    public function automationProfile(): BelongsTo
    {
        return $this->belongsTo(AutomationProfile::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(GovernanceApproval::class, 'governance_approval_id');
    }
}
