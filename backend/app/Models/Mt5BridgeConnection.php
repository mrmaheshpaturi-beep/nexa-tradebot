<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mt5BridgeConnection extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_stale_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountMappings(): HasMany
    {
        return $this->hasMany(Mt5AccountMapping::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(InstrumentAlias::class);
    }
}
