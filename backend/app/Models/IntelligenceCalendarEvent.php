<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceCalendarEvent extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'is_fabricated' => 'boolean',
            'payload' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ICE-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
