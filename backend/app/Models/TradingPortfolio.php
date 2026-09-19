<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingPortfolio extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TPF-';
    }

    protected function casts(): array
    {
        return [
            'ai_mutable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PortfolioMembership::class);
    }

    public function allocationPlans(): HasMany
    {
        return $this->hasMany(AllocationPlan::class);
    }
}
