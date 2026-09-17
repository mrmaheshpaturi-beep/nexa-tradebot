<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends BaseModel
{
    protected function casts(): array
    {
        return [
            'sidebar_collapsed' => 'boolean',
            'favorite_symbols' => 'array',
            'notifications_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
