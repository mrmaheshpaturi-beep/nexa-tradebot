<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestDataSnapshot extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'candles' => 'array',
            'mtf_candles' => 'array',
            'from_open_time' => 'datetime',
            'to_close_time' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'BDS-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }
}
