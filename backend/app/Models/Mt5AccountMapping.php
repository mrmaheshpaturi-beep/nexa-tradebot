<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mt5AccountMapping extends BaseModel
{
    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'metadata' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Mt5BridgeConnection::class, 'mt5_bridge_connection_id');
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Mt5ExternalPosition::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Mt5ExternalOrder::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Mt5ExternalDeal::class);
    }
}
