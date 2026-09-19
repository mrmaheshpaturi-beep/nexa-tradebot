<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestOptimizationTrial extends BaseModel
{
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'metrics' => 'array',
            'objective' => 'decimal:8',
            'overfit_flag' => 'boolean',
            'overfit_notes' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
