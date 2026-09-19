<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetRiskLock extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FRL-';
    }

    protected function casts(): array
    {
        return [
            'ai_created' => 'boolean',
            'activated_at' => 'datetime',
            'released_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

}
