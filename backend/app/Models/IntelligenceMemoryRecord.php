<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceMemoryRecord extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'immutable' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IMEM-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(IntelligenceAssessment::class, 'intelligence_assessment_id');
    }
}
