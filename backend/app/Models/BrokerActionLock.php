<?php

namespace App\Models;

use App\Enums\BrokerActionLockScope;
use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerActionLock extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'scope' => BrokerActionLockScope::class,
            'is_active' => 'boolean',
            'context' => 'array',
            'activated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'BALOCK-';
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
