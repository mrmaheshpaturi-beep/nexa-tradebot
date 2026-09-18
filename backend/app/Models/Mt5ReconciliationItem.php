<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mt5ReconciliationItem extends BaseModel
{
    protected function casts(): array
    {
        return ['expected' => 'array', 'observed' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Mt5ReconciliationRun::class, 'mt5_reconciliation_run_id');
    }
}
