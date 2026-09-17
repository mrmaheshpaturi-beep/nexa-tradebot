<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHeartbeat extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'environment' => TradingEnvironment::class,
            'observed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'details' => 'array',
            'metadata' => 'array',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(TradingTerminal::class, 'trading_terminal_id');
    }
}
