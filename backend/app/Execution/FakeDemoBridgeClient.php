<?php

namespace App\Execution;

use App\Contracts\DemoBridgeClient;
use App\Models\BrokerAccount;
use Illuminate\Support\Str;

/**
 * CI / unit-test fake. Never contacts real MT5. Labels source as FAKE_DEMO_BRIDGE.
 */
class FakeDemoBridgeClient implements DemoBridgeClient
{
    public bool $forceLiveTradeMode = false;

    public bool $forceUnknownTradeMode = false;

    public bool $forceTimeout = false;

    public bool $forcePartialFill = false;

    public string $login = '900001';

    public string $server = 'Nexa-Demo';

    public function verifyAccount(BrokerAccount $account): array
    {
        if ($this->forceLiveTradeMode) {
            return [
                'account_id' => $this->login,
                'server' => $this->server,
                'trade_mode' => 'LIVE',
                'source' => 'FAKE_DEMO_BRIDGE',
            ];
        }
        if ($this->forceUnknownTradeMode) {
            return [
                'account_id' => $this->login,
                'server' => $this->server,
                'trade_mode' => 'UNKNOWN',
                'source' => 'FAKE_DEMO_BRIDGE',
            ];
        }

        return [
            'account_id' => $account->broker_login ?: $this->login,
            'server' => $account->broker_server ?: $this->server,
            'trade_mode' => 'DEMO',
            'currency' => 'USD',
            'leverage' => 100,
            'balance' => '10000.00',
            'equity' => '10000.00',
            'source' => 'FAKE_DEMO_BRIDGE',
        ];
    }

    public function freshQuote(string $symbol): array
    {
        $quotes = [
            'EURUSD' => ['1.10000', '1.10020'],
            'GBPUSD' => ['1.27500', '1.27530'],
            'XAUUSD' => ['2350.10', '2350.30'],
        ];
        $pair = $quotes[strtoupper($symbol)] ?? ['1.10000', '1.10020'];

        return [
            'symbol' => strtoupper($symbol),
            'bid' => $pair[0],
            'ask' => $pair[1],
            'digits' => 5,
            'source' => 'FAKE_DEMO_BRIDGE',
            'as_of' => now()->utc()->toIso8601String(),
        ];
    }

    public function freshSymbolSpec(string $symbol): array
    {
        return [
            'symbol' => strtoupper($symbol),
            'digits' => 5,
            'point' => '0.00001',
            'volume_min' => '0.01',
            'volume_max' => '100.0',
            'volume_step' => '0.01',
            'trade_stops_level' => 0,
            'source' => 'FAKE_DEMO_BRIDGE',
        ];
    }

    public function checkAndSend(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $check = [
            'retcode' => '0',
            'comment' => 'FAKE_ORDER_CHECK_OK',
            'request_id' => $idempotencyKey,
        ];

        if (($request['account']['trade_mode'] ?? null) !== 'DEMO') {
            return [
                'check' => ['retcode' => '10017', 'comment' => 'FAKE_LIVE_REJECT'],
                'send' => null,
                'outcome' => 'REJECTED',
                'retcode' => '10017',
                'correlation_id' => $correlationId,
            ];
        }

        if ($this->forceTimeout) {
            return [
                'check' => $check,
                'send' => null,
                'outcome' => 'TIMEOUT_UNKNOWN',
                'retcode' => '10012',
                'correlation_id' => $correlationId,
            ];
        }

        $volume = (float) ($request['volume'] ?? 0);
        $filled = $this->forcePartialFill ? max(0.01, round($volume / 2, 2)) : $volume;
        $retcode = $this->forcePartialFill ? '10010' : '10009';

        return [
            'check' => $check,
            'send' => [
                'retcode' => $retcode,
                'order' => (string) random_int(800000, 899999),
                'deal' => (string) random_int(900000, 999999),
                'volume' => number_format($filled, 4, '.', ''),
                'price' => $request['price'] ?? '1.10020',
                'comment' => 'FAKE_ORDER_SEND',
                'nonce' => $nonce,
            ],
            'outcome' => $this->forcePartialFill ? 'PARTIALLY_FILLED' : 'FILLED',
            'retcode' => $retcode,
            'correlation_id' => $correlationId,
            'position_id' => (string) random_int(700000, 799999),
            'hedging' => true,
            'netting' => false,
        ];
    }

    public function syncOrders(): array
    {
        return [['ticket' => '810001', 'symbol' => 'EURUSD', 'source' => 'FAKE_DEMO_BRIDGE']];
    }

    public function syncDeals(): array
    {
        return [['ticket' => '910001', 'order' => '810001', 'source' => 'FAKE_DEMO_BRIDGE']];
    }

    public function syncPositions(): array
    {
        return [['ticket' => '710001', 'symbol' => 'EURUSD', 'volume' => '0.10', 'source' => 'FAKE_DEMO_BRIDGE']];
    }
}
