<?php

namespace App\Models;

use App\Enums\RiskLockType;
use App\Enums\RiskReasonCode;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskLock extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'lock_type' => RiskLockType::class,
            'reason_code' => RiskReasonCode::class,
            'is_active' => 'boolean',
            'blocks_new_entries' => 'boolean',
            'blocks_protective_closes' => 'boolean',
            'context' => 'array',
            'locked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-LOCK-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
