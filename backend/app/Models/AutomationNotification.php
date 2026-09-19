<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationNotification extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'read' => 'boolean',
            'payload' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ATM-NTF-';
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
