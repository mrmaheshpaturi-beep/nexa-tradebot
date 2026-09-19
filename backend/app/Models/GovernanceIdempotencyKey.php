<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceIdempotencyKey extends BaseModel
{
    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
