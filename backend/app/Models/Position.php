<?php

namespace App\Models;

use App\Enums\OrderDirection;
use App\Enums\TradingEnvironment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends BaseModel
{
    protected function casts(): array
    {
        return ['direction' => OrderDirection::class, 'environment' => TradingEnvironment::class];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function openingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'opening_order_id');
    }
}
