<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskRuleDefinition extends BaseModel
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'default_config' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(RiskEvent::class, 'rule', 'code');
    }
}
