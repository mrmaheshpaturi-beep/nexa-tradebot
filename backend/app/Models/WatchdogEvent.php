<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WatchdogEvent extends Model
{
    protected $table = "watchdog_events";

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
            "context" => "array", "duplicates_orders" => "boolean", "occurred_at" => "datetime",
        ];
    }
}