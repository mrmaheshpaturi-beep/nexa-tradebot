<?php

namespace App\Models;

use App\Enums\AutomationWorkflowState;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'state' => AutomationWorkflowState::class,
            'trace' => 'array',
            'qualification' => 'array',
            'payload' => 'array',
            'dry_run' => 'boolean',
            'broker_touched' => 'boolean',
            'signal_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ATM-WF-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AutomationSession::class, 'automation_session_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AutomationProfile::class, 'automation_profile_id');
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AutomationEvent::class);
    }
}
