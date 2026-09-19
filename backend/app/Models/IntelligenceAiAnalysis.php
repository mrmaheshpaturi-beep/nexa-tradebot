<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntelligenceAiAnalysis extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'structured_output' => 'array',
            'raw_meta' => 'array',
            'validation_errors' => 'array',
            'injection_blocked' => 'boolean',
            'mutation_tools_available' => 'boolean',
            'analyzed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IAI-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(IntelligenceAssessment::class, 'intelligence_assessment_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntelligenceChatMessage::class);
    }
}
