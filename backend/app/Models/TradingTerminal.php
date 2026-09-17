<?php

namespace App\Models;

use App\Enums\TerminalStatus;
use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingTerminal extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'status' => TerminalStatus::class,
            'last_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TradingSession::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(ServiceHeartbeat::class);
    }
}
