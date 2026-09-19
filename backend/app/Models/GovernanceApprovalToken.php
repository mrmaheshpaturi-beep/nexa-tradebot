<?php

namespace App\Models;

use App\Models\Concerns\HasSimulationPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceApprovalToken extends BaseModel
{
    use HasSimulationPublicId;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'used' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function simulationPublicIdPrefix(): string
    {
        return 'GOV-TK-';
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(GovernanceApproval::class, 'governance_approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
