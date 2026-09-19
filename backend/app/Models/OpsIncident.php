<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OpsIncident extends Model
{
    protected $table = \"ops_incidents\";

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
            "timeline" => "array", "opened_at" => "datetime", "closed_at" => "datetime",
        ];
    }
}