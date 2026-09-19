<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyValidationPolicy extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'min_win_rate' => 'float',
            'max_drawdown_pct' => 'float',
            'require_forward_validation' => 'boolean',
            'require_phase12_analytics' => 'boolean',
            'auto_approve_enabled' => 'boolean',
            'rules' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-VP-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
