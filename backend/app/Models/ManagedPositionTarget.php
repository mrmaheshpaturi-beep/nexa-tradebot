<?php

namespace App\Models;

use App\Enums\TargetHitStatus;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedPositionTarget extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'close_percent' => 'decimal:4',
            'closed_volume' => 'decimal:4',
            'status' => TargetHitStatus::class,
            'hit_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TGT-';
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }
}
