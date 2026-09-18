<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mt5ExternalPosition extends BaseModel
{
    protected function casts(): array
    {
        return ['source_updated_at' => 'datetime', 'last_seen_at' => 'datetime', 'payload' => 'array'];
    }

    public function accountMapping(): BelongsTo
    {
        return $this->belongsTo(Mt5AccountMapping::class, 'mt5_account_mapping_id');
    }
}
