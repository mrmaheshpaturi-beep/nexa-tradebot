<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingNode extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TND-';
    }

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function leases(): HasMany
    {
        return $this->hasMany(TradingNodeLease::class);
    }
}
