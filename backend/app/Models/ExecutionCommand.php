<?php

namespace App\Models;

use App\Enums\ExecutionCommandStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionFailureCode;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\GuardsStateTransitions;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExecutionCommand extends BaseModel
{
    use GuardsStateTransitions, HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'type' => ExecutionCommandType::class,
            'status' => ExecutionCommandStatus::class,
            'failure_code' => ExecutionFailureCode::class,
            'environment' => TradingEnvironment::class,
            'payload' => 'array',
            'expiration' => 'datetime',
            'requested_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'volume' => 'decimal:4',
            'price' => 'decimal:8',
            'stop_loss' => 'decimal:8',
            'take_profit' => 'decimal:8',
            'attempt_count' => 'integer',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'SIM-CMD-';
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
