<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyPortfolio extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'members' => 'array',
            'conflict_resolution' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-PF-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
