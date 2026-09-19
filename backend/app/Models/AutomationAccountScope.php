<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationAccountScope extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'AAS-';
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function fleetAccount(): BelongsTo
    {
        return $this->belongsTo(FleetAccount::class);
    }
}
