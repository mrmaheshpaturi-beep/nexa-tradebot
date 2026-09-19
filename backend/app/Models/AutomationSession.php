<?php

namespace App\Models;

use App\Enums\AutomationMode;
use App\Enums\AutomationState;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationSession extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'mode' => AutomationMode::class,
            'state' => AutomationState::class,
            'config_snapshot' => 'array',
            'auto_entry_paused' => 'boolean',
            'entries_blocked' => 'boolean',
            'safe_mode' => 'boolean',
            'kill_switch' => 'boolean',
            'preflight' => 'array',
            'counters' => 'array',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_tick_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'ATM-SES-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AutomationProfile::class, 'automation_profile_id');
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(AutomationWorkflow::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AutomationEvent::class);
    }

    public function locks(): HasMany
    {
        return $this->hasMany(AutomationLock::class);
    }
}
