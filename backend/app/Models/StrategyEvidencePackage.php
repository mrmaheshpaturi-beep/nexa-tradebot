<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyEvidencePackage extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'insufficient_samples' => 'boolean',
            'can_auto_approve' => 'boolean',
            'phase12_analytics_refs' => 'array',
            'phase15_forward_validation_refs' => 'array',
            'metrics' => 'array',
            'warnings' => 'array',
            'payload' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-EV-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GovernedStrategyVersion::class, 'governed_strategy_version_id');
    }

    public function releaseCandidate(): BelongsTo
    {
        return $this->belongsTo(StrategyReleaseCandidate::class, 'strategy_release_candidate_id');
    }
}
