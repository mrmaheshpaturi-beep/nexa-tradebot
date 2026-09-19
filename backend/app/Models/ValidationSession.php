<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ValidationSession extends Model
{
    protected $table = "validation_sessions";

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
            "config" => "array", "funnel" => "array", "rejection_analysis" => "array", "calibration" => "array", "is_backtest" => "boolean", "started_at" => "datetime", "ended_at" => "datetime",
        ];
    }
}