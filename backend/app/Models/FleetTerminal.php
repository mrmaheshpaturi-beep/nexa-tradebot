<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetTerminal extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FLT-';
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'bound_login_verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function fleetAccount(): BelongsTo
    {
        return $this->belongsTo(FleetAccount::class);
    }
}
