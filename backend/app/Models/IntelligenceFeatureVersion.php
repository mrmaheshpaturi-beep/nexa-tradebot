<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;

class IntelligenceFeatureVersion extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'fresh' => 'boolean',
            'lookahead_safe' => 'boolean',
            'extracted_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IFV-';
    }
}
