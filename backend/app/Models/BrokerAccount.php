<?php

namespace App\Models;

use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerAccount extends BaseModel
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
            'is_enabled' => 'boolean',
            'last_connected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AccountSnapshot::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function intents(): HasMany
    {
        return $this->hasMany(TradeIntent::class);
    }

    public function executionCommands(): HasMany
    {
        return $this->hasMany(ExecutionCommand::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TradingSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
