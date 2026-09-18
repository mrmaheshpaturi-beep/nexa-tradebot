<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mt5ReconciliationRun extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function accountMapping(): BelongsTo
    {
        return $this->belongsTo(Mt5AccountMapping::class, 'mt5_account_mapping_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Mt5ReconciliationItem::class);
    }
}
