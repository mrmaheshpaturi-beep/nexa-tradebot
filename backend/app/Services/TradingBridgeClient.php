<?php

namespace App\Services;

use App\Exceptions\TradingBridgeException;
use App\Models\SystemEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TradingBridgeClient
{
    private const CIRCUIT_KEY = 'mt5_bridge:circuit';

    private const STATE_KEY = 'mt5_bridge:last_state';

    public function __construct(private readonly HttpFactory $http) {}

    public function configured(): bool
    {
        return filled(config('trading_bridge.base_url')) && filled(config('trading_bridge.service_token'));
    }

    public function get(string $path, array $query = [], bool $cacheable = false): array
    {
        if (! $this->configured()) {
            throw new TradingBridgeException('BRIDGE_NOT_CONFIGURED', 'The MT5 read-only bridge is not configured.');
        }

        $circuit = Cache::get(self::CIRCUIT_KEY, ['failures' => 0, 'open_until' => 0]);
        if (($circuit['open_until'] ?? 0) > now()->timestamp) {
            throw new TradingBridgeException('BRIDGE_CIRCUIT_OPEN', 'The MT5 read-only bridge is temporarily unavailable.');
        }

        $cacheKey = 'mt5_bridge:read:'.sha1($path.'|'.json_encode($query));
        if ($cacheable && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $attempts = max(1, min(4, (int) config('trading_bridge.read_retries', 2) + 1));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http
                    ->baseUrl(rtrim((string) config('trading_bridge.base_url'), '/'))
                    ->connectTimeout((float) config('trading_bridge.connect_timeout', 1))
                    ->timeout((float) config('trading_bridge.timeout', 3))
                    ->acceptJson()
                    ->withToken((string) config('trading_bridge.service_token'))
                    ->withHeaders(['X-Correlation-ID' => (string) Str::uuid()])
                    ->get('/v1/'.ltrim($path, '/'), $query);

                if ($response->successful()) {
                    $payload = $response->json();
                    if (! is_array($payload) || ! array_key_exists('data', $payload) || ! isset($payload['meta'])) {
                        throw new TradingBridgeException('BRIDGE_INVALID_RESPONSE', 'The MT5 bridge returned an invalid response.');
                    }
                    Cache::forget(self::CIRCUIT_KEY);
                    $this->recordState('CONNECTED');
                    if ($cacheable) {
                        Cache::put($cacheKey, $payload, max(1, (int) config('trading_bridge.cache_seconds', 2)));
                    }

                    return $payload;
                }

                if (! $response->serverError()) {
                    $code = $response->status() === 404 ? 'MT5_RECORD_NOT_FOUND' : 'BRIDGE_REQUEST_REJECTED';
                    throw new TradingBridgeException($code, 'The MT5 read request was rejected.', $response->status());
                }
            } catch (ConnectionException) {
                // Bounded read-only retry below.
            }

            if ($attempt < $attempts) {
                usleep(max(0, (int) config('trading_bridge.retry_delay_ms', 100)) * 1000);
            }
        }

        $failures = (int) ($circuit['failures'] ?? 0) + 1;
        $threshold = max(1, (int) config('trading_bridge.circuit_failure_threshold', 3));
        Cache::put(self::CIRCUIT_KEY, [
            'failures' => $failures,
            'open_until' => $failures >= $threshold
                ? now()->addSeconds(max(1, (int) config('trading_bridge.circuit_open_seconds', 30)))->timestamp
                : 0,
        ], now()->addHour());
        $this->recordState($failures >= $threshold ? 'CIRCUIT_OPEN' : 'DISCONNECTED');

        throw new TradingBridgeException('BRIDGE_UNAVAILABLE', 'The MT5 read-only bridge is unavailable.');
    }

    private function recordState(string $state): void
    {
        $previous = Cache::get(self::STATE_KEY);
        if ($previous === $state) {
            return;
        }
        Cache::put(self::STATE_KEY, $state, now()->addDay());
        SystemEvent::create([
            'level' => $state === 'CONNECTED' ? 'INFO' : 'WARNING',
            'category' => 'MT5_BRIDGE',
            'message' => 'MT5 read-only bridge state changed.',
            'context' => ['previous' => $previous, 'current' => $state, 'environment' => 'DEMO'],
            'occurred_at' => now(),
        ]);
    }
}
