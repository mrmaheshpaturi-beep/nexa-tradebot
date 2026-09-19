<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionRoute extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ERT-';
    }

    protected function casts(): array
    {
        return [
            'copy_trading' => 'boolean',
            'ai_routed' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function fleetAccount(): BelongsTo
    {
        return $this->belongsTo(FleetAccount::class);
    }
}
