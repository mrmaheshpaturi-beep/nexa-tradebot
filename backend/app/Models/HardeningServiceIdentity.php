<?php

namespace App\Models;

class HardeningServiceIdentity extends BaseModel
{
    protected $table = 'hardening_service_identities';

    protected $fillable = [
        'public_id', 'service_name', 'identity_kind', 'fingerprint',
        'status', 'claims', 'issued_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'claims' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
