<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetEmergencyControl extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FEC-';
    }

    protected function casts(): array
    {
        return [
            'ai_initiated' => 'boolean',
            'activated_at' => 'datetime',
            'cleared_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

}
