<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PerformanceDriftCheck extends Model
{
    protected $table = \"performance_drift_checks\";

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
            "metrics" => "array", "auto_disable" => "boolean", "safety_block" => "boolean", "checked_at" => "datetime",
        ];
    }
}