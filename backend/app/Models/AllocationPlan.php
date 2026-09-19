<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AllocationPlan extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TAP-';
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'weights' => 'array',
            'ai_authored' => 'boolean',
            'activated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(TradingPortfolio::class, 'trading_portfolio_id');
    }
}
