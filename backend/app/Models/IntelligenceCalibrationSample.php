<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceCalibrationSample extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'predicted_confidence' => 'float',
            'outcome_positive' => 'boolean',
            'payload' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ICS-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
