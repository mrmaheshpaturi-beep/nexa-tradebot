<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSnapshot extends BaseModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
