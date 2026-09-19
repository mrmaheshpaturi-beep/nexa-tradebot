<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AnalyticsSnapshot extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'lineage' => 'array',
            'immutable' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ASN-';
    }

    protected static function booted(): void
    {
        static::updating(function (self $snap): void {
            if ($snap->getOriginal('immutable')) {
                throw ValidationException::withMessages([
                    'analytics_snapshot' => 'AnalyticsSnapshot is immutable.',
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AnalyticsDataset::class, 'analytics_dataset_id');
    }
}
