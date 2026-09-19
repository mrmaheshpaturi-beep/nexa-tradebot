<?php

namespace App\Models;

use App\Enums\StrategyLifecycleState;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernedStrategyVersion extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'lifecycle_state' => StrategyLifecycleState::class,
            'configuration' => 'array',
            'metadata' => 'array',
            'immutable' => 'boolean',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-SV-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function releaseCandidates(): HasMany
    {
        return $this->hasMany(StrategyReleaseCandidate::class);
    }

    public function evidencePackages(): HasMany
    {
        return $this->hasMany(StrategyEvidencePackage::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(StrategyDeployment::class);
    }
}
