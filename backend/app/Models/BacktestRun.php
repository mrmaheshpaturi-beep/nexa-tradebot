<?php

namespace App\Models;

use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BacktestRun extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'parameters' => 'array',
            'cost_model' => 'array',
            'closed_candle_only' => 'boolean',
            'no_lookahead' => 'boolean',
            'mtf_protected' => 'boolean',
            'lineage' => 'array',
            'metrics' => 'array',
            'equity_curve' => 'array',
            'trades' => 'array',
            'warnings' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'BTR-';
    }

    protected static function booted(): void
    {
        static::saving(function (self $run): void {
            $env = $run->environment instanceof TradingEnvironment
                ? $run->environment
                : TradingEnvironment::tryFrom((string) $run->environment);
            if ($env !== TradingEnvironment::Backtest) {
                throw ValidationException::withMessages([
                    'environment' => 'BacktestRun environment must be BACKTEST (never DEMO/LIVE).',
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dataSnapshot(): BelongsTo
    {
        return $this->belongsTo(BacktestDataSnapshot::class, 'backtest_data_snapshot_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BacktestJob::class);
    }

    public function folds(): HasMany
    {
        return $this->hasMany(BacktestWalkForwardFold::class);
    }

    public function trials(): HasMany
    {
        return $this->hasMany(BacktestOptimizationTrial::class);
    }

    public function monteCarloPaths(): HasMany
    {
        return $this->hasMany(BacktestMonteCarloPath::class);
    }
}
