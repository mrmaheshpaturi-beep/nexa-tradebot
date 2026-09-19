<?php

namespace App\Models;

use App\Enums\AutomationLockType;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationLock extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'lock_type' => AutomationLockType::class,
            'active' => 'boolean',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ATM-LCK-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AutomationSession::class, 'automation_session_id');
    }
}
