<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MetricSample extends Model
{
    protected $table = "metric_samples";

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
            "labels" => "array", "insufficient_sample" => "boolean", "value" => "float", "observed_at" => "datetime",
        ];
    }
}