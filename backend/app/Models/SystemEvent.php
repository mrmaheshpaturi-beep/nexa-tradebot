<?php

namespace App\Models;

class SystemEvent extends BaseModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'datetime'];
    }
}
