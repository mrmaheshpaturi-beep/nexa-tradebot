<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerInstrument extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'BIN-';
    }

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'spec_fetched_at' => 'datetime',
            'spec_fresh_until' => 'datetime',
            'spec_stale' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function canonical(): BelongsTo
    {
        return $this->belongsTo(CanonicalInstrument::class, 'canonical_instrument_id');
    }
}
