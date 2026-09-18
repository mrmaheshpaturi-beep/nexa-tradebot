<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MarketSnapshot extends BaseModel
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
