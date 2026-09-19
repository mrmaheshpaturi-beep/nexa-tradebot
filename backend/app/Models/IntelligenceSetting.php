<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceSetting extends BaseModel
{
    protected function casts(): array
    {
        return [
            'paid_providers_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
