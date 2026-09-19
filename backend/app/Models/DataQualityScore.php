<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DataQualityScore extends Model
{
    protected $table = "data_quality_scores";

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
            "issues" => "array", "blocks_new_trades" => "boolean", "score" => "float", "clock_drift_ms" => "float", "observed_at" => "datetime",
        ];
    }
}