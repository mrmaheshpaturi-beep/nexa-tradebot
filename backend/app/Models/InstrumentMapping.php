<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstrumentMapping extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'IMAP-';
    }

    protected function casts(): array
    {
        return [
            'contract_multiplier' => 'float',
            'metadata' => 'array',
        ];
    }

}
