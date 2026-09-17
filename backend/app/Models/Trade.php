<?php

namespace App\Models;

use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends BaseModel
{
    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
