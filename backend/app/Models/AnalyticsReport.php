<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsReport extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['body' => 'array'];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ARP-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSnapshot::class, 'analytics_snapshot_id');
    }
}
