<?php

namespace App\Models;

use App\Enums\PositionManagementActionType;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagementConfirmation extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'action_type' => PositionManagementActionType::class,
            'preview_payload' => 'array',
            'fresh_context' => 'array',
            'step1_at' => 'datetime',
            'step2_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'MCONF-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function managedPosition(): BelongsTo
    {
        return $this->belongsTo(ManagedPosition::class);
    }
}
