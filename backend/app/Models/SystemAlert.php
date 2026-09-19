<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemAlert extends Model
{
    protected $table = "system_alerts";

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
            "payload" => "array", "first_seen_at" => "datetime", "last_seen_at" => "datetime", "acked_at" => "datetime", "resolved_at" => "datetime", "cooldown_until" => "datetime",
        ];
    }
}