<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioMembership extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TPM-';
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(TradingPortfolio::class, 'trading_portfolio_id');
    }

    public function fleetAccount(): BelongsTo
    {
        return $this->belongsTo(FleetAccount::class);
    }
}
