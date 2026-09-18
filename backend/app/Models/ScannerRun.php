<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScannerRun extends BaseModel
{
    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'summary' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(ScannerConfig::class, 'scanner_config_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(SignalCandidate::class);
    }

    public function alertEvents(): HasMany
    {
        return $this->hasMany(ScannerAlertEvent::class);
    }
}
