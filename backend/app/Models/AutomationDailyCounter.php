<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationDailyCounter extends BaseModel
{
    protected function casts(): array
    {
        return [
            'realized_pnl' => 'decimal:4',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AutomationSession::class, 'automation_session_id');
    }
}
