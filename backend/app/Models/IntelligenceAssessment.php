<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntelligenceAssessment extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'technical' => 'array',
            'mtf' => 'array',
            'regime' => 'array',
            'ensemble' => 'array',
            'market_quality' => 'array',
            'volatility' => 'array',
            'spread' => 'array',
            'anomaly' => 'array',
            'calendar' => 'array',
            'news' => 'array',
            'opportunity' => 'array',
            'evidence_buckets' => 'array',
            'rules_fired' => 'array',
            'payload' => 'array',
            'confidence' => 'float',
            'live_execution' => 'boolean',
            'order_send' => 'boolean',
            'assessed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IAS-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(IntelligenceOpportunity::class);
    }

    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(IntelligenceAiAnalysis::class);
    }
}
