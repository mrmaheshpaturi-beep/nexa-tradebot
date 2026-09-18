<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mt5SyncCursor extends BaseModel
{
    protected function casts(): array
    {
        return ['last_source_at' => 'datetime', 'last_synced_at' => 'datetime', 'metadata' => 'array'];
    }

    public function accountMapping(): BelongsTo
    {
        return $this->belongsTo(Mt5AccountMapping::class, 'mt5_account_mapping_id');
    }
}
