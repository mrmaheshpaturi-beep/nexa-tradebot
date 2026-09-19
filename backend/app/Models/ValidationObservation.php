<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ValidationObservation extends Model
{
    protected $table = "validation_observations";

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
            "payload" => "array", "research_only" => "boolean", "observed_at" => "datetime",
        ];
    }
}