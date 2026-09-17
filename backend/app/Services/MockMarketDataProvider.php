<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\Timeframe;
use App\Models\TradingInstrument;
use Illuminate\Validation\ValidationException;

class MockMarketDataProvider implements MarketDataProvider
{
    private const PRICES = [
        'EURUSD' => ['1.10000', '1.10020'],
        'GBPUSD' => ['1.27500', '1.27530'],
        'USDJPY' => ['145.100', '145.120'],
        'XAUUSD' => ['2350.10', '2350.30'],
        'NAS100' => ['19000.00', '19001.00'],
        'BTCUSD' => ['60000.00', '60010.00'],
    ];

    public function getQuote(string $symbol): array
    {
        $prices = self::PRICES[$symbol] ?? null;
        if ($prices === null) {
            throw ValidationException::withMessages(['symbol' => 'No deterministic mock quote is available.']);
        }

        return [
            'symbol' => $symbol,
            'bid' => $prices[0],
            'ask' => $prices[1],
            'spread' => (string) round((float) $prices[1] - (float) $prices[0], 10),
            'timestamp' => now()->utc()->toIso8601String(),
            'source' => 'MOCK',
            'environment' => 'SIMULATION',
        ];
    }

    public function getQuotes(array $symbols): array
    {
        return collect($symbols)->mapWithKeys(fn (string $symbol): array => [$symbol => $this->getQuote($symbol)])->all();
    }

    public function getCandles(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        $quote = $this->getQuote($symbol);
        $base = (float) $quote['bid'];
        $step = max($base * 0.0001, 0.00001);

        return collect(range(0, max(0, min($limit, 500) - 1)))->map(function (int $offset) use ($base, $step, $symbol, $timeframe): array {
            $open = $base + (($offset % 5) - 2) * $step;
            $close = $open + (($offset % 2 === 0) ? $step : -$step);

            return [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'open_time' => now()->utc()->subMinutes($offset + 1)->toIso8601String(),
                'close_time' => now()->utc()->subMinutes($offset)->toIso8601String(),
                'open' => (string) round($open, 8),
                'high' => (string) round(max($open, $close) + $step, 8),
                'low' => (string) round(min($open, $close) - $step, 8),
                'close' => (string) round($close, 8),
                'tick_volume' => 100 + $offset,
                'source' => 'MOCK',
                'environment' => 'SIMULATION',
            ];
        })->reverse()->values()->all();
    }

    public function getSymbolSpecification(string $symbol): array
    {
        return TradingInstrument::where('symbol', $symbol)->firstOrFail()->toArray();
    }
}
