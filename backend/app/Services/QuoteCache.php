<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * In-memory (cache-backed) latest quote store for monitored symbols.
 */
class QuoteCache
{
    private const KEY = 'market_data:latest_quotes';

    /**
     * @param  array<string, mixed>  $quote
     */
    public function put(string $symbol, array $quote): void
    {
        $all = Cache::get(self::KEY, []);
        $all[strtoupper($symbol)] = $quote;
        Cache::put(self::KEY, $all, now()->addMinutes(30));
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     */
    public function putMany(array $quotes): void
    {
        foreach ($quotes as $quote) {
            if (isset($quote['symbol'])) {
                $this->put((string) $quote['symbol'], $quote);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $symbol): ?array
    {
        $all = Cache::get(self::KEY, []);

        return $all[strtoupper($symbol)] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return Cache::get(self::KEY, []);
    }
}
