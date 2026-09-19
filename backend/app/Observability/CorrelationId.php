<?php

namespace App\Observability;

use Illuminate\Support\Str;

/**
 * Correlation ID helpers for structured logging.
 */
class CorrelationId
{
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = (string) Str::uuid();
        }

        return self::$current;
    }

    public static function set(?string $id): string
    {
        self::$current = $id ?: (string) Str::uuid();

        return self::$current;
    }

    public static function clear(): void
    {
        self::$current = null;
    }
}
