<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDatasetRow extends BaseModel
{
    protected function casts(): array
    {
        return [
            'realized_pnl' => 'decimal:4',
            'r_multiple' => 'decimal:8',
            'mae' => 'decimal:8',
            'mfe' => 'decimal:8',
            'spread_cost' => 'decimal:8',
            'commission' => 'decimal:8',
            'slippage' => 'decimal:8',
            'swap' => 'decimal:8',
            'payload' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AnalyticsDataset::class, 'analytics_dataset_id');
    }
}
