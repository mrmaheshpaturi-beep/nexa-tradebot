<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ResearchComparison extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'comparison' => 'array',
            'warnings' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'RCP-';
    }

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            if (strtoupper((string) $row->backtest_label) !== 'BACKTEST') {
                throw ValidationException::withMessages(['backtest_label' => 'Must be BACKTEST.']);
            }
            if (strtoupper((string) $row->demo_label) !== 'DEMO') {
                throw ValidationException::withMessages(['demo_label' => 'Must be DEMO.']);
            }
            if (strtoupper((string) $row->backtest_label) === strtoupper((string) $row->demo_label)) {
                throw ValidationException::withMessages(['labels' => 'BACKTEST and DEMO labels must remain distinct.']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }

    public function analyticsSnapshot(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSnapshot::class);
    }
}
