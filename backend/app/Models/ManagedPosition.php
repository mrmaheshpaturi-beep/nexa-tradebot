<?php

namespace App\Models;

use App\Enums\CloseReason;
use App\Enums\ManagementStatus;
use App\Enums\OrderDirection;
use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManagedPosition extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'ownership' => PositionOwnership::class,
            'management_status' => ManagementStatus::class,
            'direction' => OrderDirection::class,
            'environment' => TradingEnvironment::class,
            'close_reason' => CloseReason::class,
            'initial_volume' => 'decimal:4',
            'current_volume' => 'decimal:4',
            'entry_price' => 'decimal:8',
            'initial_stop_loss' => 'decimal:8',
            'current_stop_loss' => 'decimal:8',
            'initial_take_profit' => 'decimal:8',
            'current_take_profit' => 'decimal:8',
            'realized_profit' => 'decimal:4',
            'floating_profit' => 'decimal:4',
            'r_multiple' => 'decimal:8',
            'mae' => 'decimal:8',
            'mfe' => 'decimal:8',
            'break_even_applied' => 'boolean',
            'trailing_active' => 'boolean',
            'trailing_extreme' => 'decimal:8',
            'last_trail_stop' => 'decimal:8',
            'auto_management_paused' => 'boolean',
            'targets' => 'array',
            'metadata' => 'array',
            'break_even_applied_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_managed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'MPOS-';
    }

    public function isNexaOwned(): bool
    {
        return $this->ownership === PositionOwnership::NexaManaged;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(TradeManagementPolicy::class, 'management_policy_id');
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TradeManagementDecision::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PositionManagementAction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TradeManagementEvent::class);
    }

    public function positionTargets(): HasMany
    {
        return $this->hasMany(ManagedPositionTarget::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ManagedPositionSnapshot::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(TradeSummary::class);
    }
}
