<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeadLetterJob extends Model
{
    protected $table = "dead_letter_jobs";

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
            "payload" => "array", "failed_at" => "datetime",
        ];
    }
}