<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasSimulationPublicId
{
    protected static function bootHasSimulationPublicId(): void
    {
        static::creating(function ($model): void {
            if (! $model->public_id) {
                $model->public_id = static::simulationPublicIdPrefix().Str::upper((string) Str::ulid());
            }
        });
    }

    abstract protected static function simulationPublicIdPrefix(): string;
}
