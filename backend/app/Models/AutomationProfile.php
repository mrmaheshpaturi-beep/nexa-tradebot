<?php

namespace App\Models;

use App\Enums\AutomationProfileStatus;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationProfile extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationProfileStatus::class,
            'symbol_universe' => 'array',
            'timeframe_universe' => 'array',
            'strategy_matrix' => 'array',
            'qualification_rules' => 'array',
            'risk_overrides' => 'array',
            'reentry_policy' => 'array',
            'session_policy' => 'array',
            'calendar_policy' => 'array',
            'intelligence_required' => 'boolean',
            'closed_candle_only' => 'boolean',
            'allow_revenge_trading' => 'boolean',
            'allow_martingale' => 'boolean',
            'online_self_optimization' => 'boolean',
            'validated_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ATM-PRF-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AutomationSession::class);
    }
}
