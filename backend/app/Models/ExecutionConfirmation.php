<?php

namespace App\Models;

use App\Enums\ExecutionConfirmationStatus;
use App\Enums\TradingEnvironment;
use App\Models\Concerns\GuardsStateTransitions;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionConfirmation extends BaseModel
{
    use GuardsStateTransitions, HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'status' => ExecutionConfirmationStatus::class,
            'step' => 'integer',
            'preview_payload' => 'array',
            'fresh_context' => 'array',
            'step1_at' => 'datetime',
            'step2_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'EXE-CNF-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeIntent(): BelongsTo
    {
        return $this->belongsTo(TradeIntent::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
