<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetAccount extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FLA-';
    }

    protected function casts(): array
    {
        return [
            'environment_verified_at' => 'datetime',
            'trading_enabled' => 'boolean',
            'safe_mode' => 'boolean',
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BrokerProvider::class, 'broker_provider_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BrokerConnection::class, 'broker_connection_id');
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class, 'broker_account_id');
    }

    public function fingerprints(): HasMany
    {
        return $this->hasMany(AccountFingerprint::class);
    }
}
