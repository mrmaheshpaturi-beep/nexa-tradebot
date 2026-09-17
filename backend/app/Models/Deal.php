<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\OrderDirection;
use App\Enums\TradeOrigin;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'direction' => OrderDirection::class,
            'side' => OrderDirection::class,
            'type' => DealType::class,
            'origin' => TradeOrigin::class,
            'environment' => TradingEnvironment::class,
            'dealt_at' => 'datetime',
            'executed_at' => 'datetime',
            'metadata' => 'array',
            'volume' => 'decimal:4',
            'price' => 'decimal:8',
            'commission' => 'decimal:4',
            'swap' => 'decimal:4',
            'fee' => 'decimal:4',
            'profit' => 'decimal:4',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-DEAL-';
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

    public function executionCommand(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class);
    }
}
