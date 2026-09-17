<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends BaseModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'direction' => OrderDirection::class,
            'environment' => TradingEnvironment::class,
            'dealt_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
