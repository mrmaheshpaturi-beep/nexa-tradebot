<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CircuitBreakerRecord extends Model
{
    protected $table = "circuit_breakers";

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            "metadata" => "array", "opened_at" => "datetime", "half_open_at" => "datetime",
        ];
    }
}