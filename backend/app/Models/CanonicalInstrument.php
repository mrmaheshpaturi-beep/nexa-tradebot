<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalInstrument extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'CIN-';
    }

    protected function casts(): array
    {
        return [
            'pip_size' => 'float',
            'metadata' => 'array',
            'digits' => 'integer',
        ];
    }

}
