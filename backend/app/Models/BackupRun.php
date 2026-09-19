<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BackupRun extends Model
{
    protected $table = \"backup_runs\";

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
            "manifest" => "array", "verified" => "boolean", "restore_tested" => "boolean", "reconcile_required_before_trading" => "boolean", "started_at" => "datetime", "finished_at" => "datetime",
        ];
    }
}