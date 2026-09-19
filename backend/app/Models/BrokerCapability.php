<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerCapability extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FLCAP-';
    }

    protected function casts(): array
    {
        return [
            'supported' => 'boolean',
            'enabled' => 'boolean',
            'limits' => 'array',
            'metadata' => 'array',
        ];
    }

}
