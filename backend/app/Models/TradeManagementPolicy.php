<?php

namespace App\Models;

use App\Enums\ManualChangeDisposition;
use App\Enums\TrailingType;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeManagementPolicy extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'break_even_enabled' => 'boolean',
            'break_even_trigger_value' => 'decimal:8',
            'break_even_offset' => 'decimal:8',
            'trailing_enabled' => 'boolean',
            'trailing_type' => TrailingType::class,
            'trailing_start' => 'decimal:8',
            'trailing_distance' => 'decimal:8',
            'trailing_step' => 'decimal:8',
            'trailing_atr_multiplier' => 'decimal:8',
            'partial_close_enabled' => 'boolean',
            'partial_close_levels' => 'array',
            'take_profit_management' => 'boolean',
            'time_exit_enabled' => 'boolean',
            'strategy_invalidation_exit' => 'boolean',
            'session_exit' => 'boolean',
            'weekend_exit' => 'boolean',
            'risk_exit' => 'boolean',
            'emergency_exit' => 'boolean',
            'manual_change_disposition' => ManualChangeDisposition::class,
            'config' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'TMPOL-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function managedPositions(): HasMany
    {
        return $this->hasMany(ManagedPosition::class, 'management_policy_id');
    }
}
