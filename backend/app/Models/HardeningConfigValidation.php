<?php

namespace App\Models;

class HardeningConfigValidation extends BaseModel
{
    protected $table = 'hardening_config_validations';

    protected $fillable = [
        'public_id', 'app_environment', 'broker_trade_mode', 'ok',
        'errors', 'warnings', 'safe_defaults', 'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'errors' => 'array',
            'warnings' => 'array',
            'safe_defaults' => 'array',
            'validated_at' => 'datetime',
        ];
    }
}
