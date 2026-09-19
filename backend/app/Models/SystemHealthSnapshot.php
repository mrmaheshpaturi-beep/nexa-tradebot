<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemHealthSnapshot extends Model
{
    protected $table = "system_health_snapshots";

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
            "dependencies" => "array", "checks" => "array", "new_entries_blocked" => "boolean", "observed_at" => "datetime",
        ];
    }
}