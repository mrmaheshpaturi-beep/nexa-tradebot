<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceNewsItem extends BaseModel
{
    use HasSimulationPublicId;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_fabricated' => 'boolean',
            'payload' => 'array',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'INI-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
