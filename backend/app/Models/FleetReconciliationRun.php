<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetReconciliationRun extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FRR-';
    }

    protected function casts(): array
    {
        return [
            'foreign_positions' => 'integer',
            'matched_positions' => 'integer',
            'mismatches' => 'integer',
            'safe_mode_triggered' => 'boolean',
            'restart_recovery' => 'boolean',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

}
