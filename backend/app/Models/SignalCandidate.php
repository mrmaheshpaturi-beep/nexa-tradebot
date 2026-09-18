<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SignalCandidate extends BaseModel
{
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! $model->public_id) {
                $model->public_id = 'CAND-'.Str::upper(Str::random(12));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'conflict_flags' => 'array',
            'score_breakdown' => 'array',
            'confluence' => 'array',
            'evidence' => 'array',
            'quality' => 'array',
            'freshness' => 'array',
            'metadata' => 'array',
            'marked_for_simulate' => 'boolean',
            'rank_score' => 'float',
            'confluence_score' => 'float',
            'expires_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScannerRun::class, 'scanner_run_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function alertEvents(): HasMany
    {
        return $this->hasMany(ScannerAlertEvent::class);
    }
}
