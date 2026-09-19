<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedPositionSnapshot extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'MPSNAP-';
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }
}
