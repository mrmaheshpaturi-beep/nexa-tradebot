<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentAlias extends BaseModel
{
    protected function casts(): array
    {
        return ['external_spec' => 'array', 'spec_observed_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Mt5BridgeConnection::class, 'mt5_bridge_connection_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
