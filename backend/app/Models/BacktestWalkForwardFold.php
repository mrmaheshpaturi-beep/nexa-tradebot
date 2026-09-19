<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestWalkForwardFold extends BaseModel
{
    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'parameters_used' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
