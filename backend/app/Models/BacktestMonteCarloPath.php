<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestMonteCarloPath extends BaseModel
{
    protected function casts(): array
    {
        return [
            'equity_curve' => 'array',
            'metrics' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
