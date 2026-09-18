<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScannerConfig extends BaseModel
{
    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'timeframes' => 'array',
            'plugin_keys' => 'array',
            'strategy_ids' => 'array',
            'enabled' => 'boolean',
            'create_signals' => 'boolean',
            'create_candidates' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScannerRun::class);
    }
}
