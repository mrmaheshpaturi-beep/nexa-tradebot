<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingNodeLease extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TNL-';
    }

    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'split_brain_detected' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(TradingNode::class, 'trading_node_id');
    }

    public function fleetAccount(): BelongsTo
    {
        return $this->belongsTo(FleetAccount::class);
    }
}
