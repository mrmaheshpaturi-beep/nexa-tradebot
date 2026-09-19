<?php

namespace App\Execution;

use App\Contracts\DemoBridgeClient;
use App\Exceptions\TradingBridgeException;
use App\Models\BrokerAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Authenticated DEMO write client with nonce/timestamp replay protection.
 * Does not perform order_send locally — delegates to the bridge sole path.
 */
class TradingBridgeDemoClient implements DemoBridgeClient
{
    public function __construct(private readonly HttpFactory $http) {}

    public function configured(): bool
    {
        return filled(config('trading_bridge.base_url')) && filled(config('trading_bridge.service_token'));
    }

    public function verifyAccount(BrokerAccount $account): array
    {
        $payload = $this->request('GET', 'account', [], true);
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new TradingBridgeException('BRIDGE_INVALID_RESPONSE', 'Invalid DEMO account payload.');
        }
        $data['source'] = $data['source'] ?? 'MT5_DEMO_BRIDGE';

        return $data;
    }

    public function freshQuote(string $symbol): array
    {
        $payload = $this->request('GET', 'quotes/'.rawurlencode($symbol));
        $data = $payload['data'] ?? [];
        $data['source'] = $data['source'] ?? 'MT5_DEMO_BRIDGE';

        return $data;
    }

    public function freshSymbolSpec(string $symbol): array
    {
        $payload = $this->request('GET', 'symbols/'.rawurlencode($symbol));
        $data = $payload['data'] ?? [];
        $data['source'] = $data['source'] ?? 'MT5_DEMO_BRIDGE';

        return $data;
    }

    public function checkAndSend(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $timestamp = (string) now()->timestamp;
        $payload = $this->request('POST', 'execution/demo/check-and-send', [
            'request' => $request,
            'idempotency_key' => $idempotencyKey,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
            'correlation_id' => $correlationId,
        ], false, [
            'X-Nexa-Nonce' => $nonce,
            'X-Nexa-Timestamp' => $timestamp,
            'X-Nexa-Idempotency-Key' => $idempotencyKey,
            'X-Correlation-ID' => $correlationId,
        ]);

        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new TradingBridgeException('BRIDGE_INVALID_RESPONSE', 'Invalid DEMO execution payload.');
        }

        return $data;
    }

    public function syncOrders(): array
    {
        return ($this->request('GET', 'orders')['data'] ?? []) ?: [];
    }

    public function syncDeals(): array
    {
        return ($this->request('GET', 'history/deals', [
            'date_from' => now()->subDay()->toIso8601String(),
            'date_to' => now()->toIso8601String(),
            'limit' => 100,
        ])['data'] ?? []) ?: [];
    }

    public function syncPositions(): array
    {
        return ($this->request('GET', 'positions')['data'] ?? []) ?: [];
    }

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body = [], bool $cacheable = false, array $headers = []): array
    {
        if (! $this->configured()) {
            throw new TradingBridgeException('BRIDGE_NOT_CONFIGURED', 'The MT5 DEMO bridge is not configured.');
        }

        $cacheKey = 'mt5_bridge:demo:'.sha1($method.'|'.$path.'|'.json_encode($body));
        if ($cacheable && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        try {
            $pending = $this->http
                ->baseUrl(rtrim((string) config('trading_bridge.base_url'), '/'))
                ->connectTimeout((float) config('trading_bridge.connect_timeout', 1))
                ->timeout((float) config('trading_bridge.write_timeout', config('trading_bridge.timeout', 5)))
                ->acceptJson()
                ->withToken((string) config('trading_bridge.service_token'))
                ->withHeaders(array_merge([
                    'X-Correlation-ID' => $headers['X-Correlation-ID'] ?? (string) Str::uuid(),
                ], $headers));

            $response = strtoupper($method) === 'POST'
                ? $pending->post('/v1/'.ltrim($path, '/'), $body)
                : $pending->get('/v1/'.ltrim($path, '/'), $body);

            if ($response->successful()) {
                $payload = $response->json();
                if (! is_array($payload) || ! array_key_exists('data', $payload)) {
                    throw new TradingBridgeException('BRIDGE_INVALID_RESPONSE', 'The MT5 DEMO bridge returned an invalid response.');
                }
                if ($cacheable) {
                    Cache::put($cacheKey, $payload, 2);
                }

                return $payload;
            }

            throw new TradingBridgeException(
                'BRIDGE_REQUEST_REJECTED',
                'The MT5 DEMO bridge rejected the request.',
                $response->status(),
            );
        } catch (ConnectionException) {
            throw new TradingBridgeException('BRIDGE_UNAVAILABLE', 'The MT5 DEMO bridge is unavailable.');
        }
    }
}
