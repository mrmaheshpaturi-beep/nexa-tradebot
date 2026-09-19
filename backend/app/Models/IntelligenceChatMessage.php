<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceChatMessage extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'meta' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ICH-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(IntelligenceAiAnalysis::class, 'intelligence_ai_analysis_id');
    }
}
