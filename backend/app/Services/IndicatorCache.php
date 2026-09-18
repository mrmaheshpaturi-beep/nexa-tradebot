<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for computed indicator series (no Redis required).
 */
class IndicatorCache
{
    private const PREFIX = 'indicator_engine:';

    private const TTL_SECONDS = 30;

    public function key(string $instrument, string $timeframe, string $indicator, array $params, string $prefer, int $count): string
    {
        ksort($params);

        return self::PREFIX.hash('sha256', json_encode([
            strtoupper($instrument),
            strtoupper($timeframe),
            strtoupper($indicator),
            $params,
            strtolower($prefer),
            $count,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $key, array $payload): void
    {
        Cache::put($key, $payload, now()->addSeconds(self::TTL_SECONDS));
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }
}
