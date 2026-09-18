<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SpreadEngine
{
    private const HISTORY_KEY = 'market_data:spread_history';

    private const HISTORY_LIMIT = 64;

    /**
     * @return array{raw:string,points:string|null,digits:int|null}
     */
    public function calculate(string $bid, string $ask, ?int $digits = null): array
    {
        $raw = (string) round(((float) $ask) - ((float) $bid), 10);
        $points = null;
        if ($digits !== null && $digits >= 0) {
            $point = 10 ** (-1 * $digits);
            $points = $point > 0 ? (string) round(((float) $raw) / $point, 4) : null;
        }

        return [
            'raw' => $raw,
            'points' => $points,
            'digits' => $digits,
        ];
    }

    public function remember(string $symbol, string $spreadRaw): void
    {
        $history = Cache::get(self::HISTORY_KEY, []);
        $bucket = $history[$symbol] ?? [];
        $bucket[] = ['spread' => (float) $spreadRaw, 'at' => now()->timestamp];
        if (count($bucket) > self::HISTORY_LIMIT) {
            $bucket = array_slice($bucket, -self::HISTORY_LIMIT);
        }
        $history[$symbol] = $bucket;
        Cache::put(self::HISTORY_KEY, $history, now()->addDay());
    }

    /**
     * @return array{current:float|null,average:float|null,maximum:float|null,samples:int}
     */
    public function summary(string $symbol): array
    {
        $history = Cache::get(self::HISTORY_KEY, []);
        $bucket = $history[$symbol] ?? [];
        if ($bucket === []) {
            return ['current' => null, 'average' => null, 'maximum' => null, 'samples' => 0];
        }
        $values = array_map(fn (array $row): float => (float) $row['spread'], $bucket);

        return [
            'current' => end($values) ?: null,
            'average' => round(array_sum($values) / count($values), 10),
            'maximum' => max($values),
            'samples' => count($values),
        ];
    }
}
