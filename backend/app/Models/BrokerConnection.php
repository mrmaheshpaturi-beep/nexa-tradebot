<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerConnection extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'FLC-';
    }

    protected function casts(): array
    {
        return [
            'last_health_at' => 'datetime',
            'health_payload' => 'array',
            'secret_ref' => 'array',
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

    public function fleetAccounts(): HasMany
    {
        return $this->hasMany(FleetAccount::class);
    }
}
