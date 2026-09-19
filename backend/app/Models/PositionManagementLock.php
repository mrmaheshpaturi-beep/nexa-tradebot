<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionManagementLock extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'PMLOCK-';
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(PositionManagementAction::class, 'position_management_action_id');
    }
}
