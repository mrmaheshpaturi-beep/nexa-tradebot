<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceAdvancedSnapshot extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'deep_structure' => 'array',
            'mtf_matrix' => 'array',
            'ensemble' => 'array',
            'cross_market' => 'array',
            'analogs' => 'array',
            'context_pack' => 'array',
            'uncertainty' => 'array',
            'suitability' => 'array',
            'scoring_separation' => 'array',
            'payload' => 'array',
            'confidence' => 'float',
            'live_execution' => 'boolean',
            'order_send' => 'boolean',
            'assessed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IASV-';
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
