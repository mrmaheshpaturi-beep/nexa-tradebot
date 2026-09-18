<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\Timeframe;
use App\Exceptions\TradingBridgeException;
use App\Models\TradingInstrument;
use Illuminate\Validation\ValidationException;

/**
 * MT5 DEMO read-only market data provider via TradingBridgeClient.
 * Never silently substitutes mock prices.
 */
class Mt5MarketDataProvider implements MarketDataProvider
{
    public function __construct(private readonly TradingBridgeClient $bridge) {}

    public function getQuote(string $symbol): array
    {
        $payload = $this->bridge->get('market/quotes/'.strtoupper($symbol), [], cacheable: true);
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new TradingBridgeException('BRIDGE_INVALID_RESPONSE', 'The MT5 market quote response was invalid.');
        }

        return [
            'symbol' => (string) ($data['symbol'] ?? strtoupper($symbol)),
            'bid' => (string) ($data['bid'] ?? ''),
            'ask' => (string) ($data['ask'] ?? ''),
            'spread' => (string) ($data['spread'] ?? ''),
            'timestamp' => (string) ($data['timestamp'] ?? now()->toIso8601String()),
            'source' => (string) ($data['source'] ?? 'MT5'),
            'environment' => (string) ($data['environment'] ?? 'DEMO'),
            'engine' => $data,
        ];
    }

    public function getQuotes(array $symbols): array
    {
        $query = $symbols === [] ? [] : ['symbols' => implode(',', array_map('strtoupper', $symbols))];
        $payload = $this->bridge->get('market/quotes', $query, cacheable: true);
        $rows = $payload['data'] ?? [];
        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['symbol'])) {
                continue;
            }
            $mapped[(string) $row['symbol']] = [
                'symbol' => (string) $row['symbol'],
                'bid' => (string) ($row['bid'] ?? ''),
                'ask' => (string) ($row['ask'] ?? ''),
                'spread' => (string) ($row['spread'] ?? ''),
                'timestamp' => (string) ($row['timestamp'] ?? now()->toIso8601String()),
                'source' => (string) ($row['source'] ?? 'MT5'),
                'environment' => (string) ($row['environment'] ?? 'DEMO'),
                'engine' => $row,
            ];
        }

        return $mapped;
    }

    public function getCandles(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        $payload = $this->bridge->get('market/candles/'.strtoupper($symbol), [
            'timeframe' => $timeframe->value,
            'count' => max(1, min(1000, $limit)),
        ], cacheable: true);
        $rows = $payload['data'] ?? [];

        return is_array($rows) ? $rows : [];
    }

    public function getSymbolSpecification(string $symbol): array
    {
        try {
            $payload = $this->bridge->get('market/symbols', [], cacheable: true);
            foreach ($payload['data'] ?? [] as $row) {
                if (is_array($row) && strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol)) {
                    return $row;
                }
            }
        } catch (TradingBridgeException) {
            // fall through to local instrument master
        }

        $instrument = TradingInstrument::query()->where('symbol', strtoupper($symbol))->first();
        if ($instrument === null) {
            throw ValidationException::withMessages(['symbol' => 'Symbol specification was not found.']);
        }

        return $instrument->toArray();
    }
}
