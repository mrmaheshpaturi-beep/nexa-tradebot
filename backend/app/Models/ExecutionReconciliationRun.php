<?php

namespace App\Models;

use App\Enums\TradingEnvironment;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionReconciliationRun extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'environment' => TradingEnvironment::class,
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'orders_synced' => 'integer',
            'deals_synced' => 'integer',
            'positions_synced' => 'integer',
            'mismatches' => 'integer',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'EXE-REC-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
