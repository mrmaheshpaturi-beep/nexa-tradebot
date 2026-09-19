<?php

namespace App\Models;

use App\Enums\RiskReservationStatus;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskReservation extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'status' => RiskReservationStatus::class,
            'reserved_margin' => 'decimal:4',
            'reserved_risk' => 'decimal:4',
            'reserved_exposure' => 'decimal:4',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-RSV-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function riskDecision(): BelongsTo
    {
        return $this->belongsTo(RiskDecision::class);
    }
}
