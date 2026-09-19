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

    public bool $forceRejectManagement = false;

    /** @var array<string,array<string,mixed>> */
    public array $positions = [];

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
        return $this->authorizedWrite('PLACE_ORDER', $request, $idempotencyKey, $nonce, $correlationId);
    }

    public function modifyPositionProtection(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $request['action'] = 'MODIFY_POSITION_PROTECTION';

        return $this->authorizedWrite('MODIFY_POSITION_PROTECTION', $request, $idempotencyKey, $nonce, $correlationId);
    }

    public function closePosition(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $request['action'] = 'CLOSE_POSITION';

        return $this->authorizedWrite('CLOSE_POSITION', $request, $idempotencyKey, $nonce, $correlationId);
    }

    public function partialClose(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $request['action'] = 'PARTIAL_CLOSE';

        return $this->authorizedWrite('PARTIAL_CLOSE', $request, $idempotencyKey, $nonce, $correlationId);
    }

    public function cancelPendingOrder(array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $request['action'] = 'CANCEL_PENDING';

        return $this->authorizedWrite('CANCEL_PENDING', $request, $idempotencyKey, $nonce, $correlationId);
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function authorizedWrite(string $action, array $request, string $idempotencyKey, string $nonce, string $correlationId): array
    {
        $check = [
            'retcode' => '0',
            'comment' => 'FAKE_ORDER_CHECK_OK',
            'request_id' => $idempotencyKey,
            'action' => $action,
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

        if ($this->forceRejectManagement && $action !== 'PLACE_ORDER') {
            return [
                'check' => ['retcode' => '10016', 'comment' => 'FAKE_MGMT_REJECT'],
                'send' => null,
                'outcome' => 'REJECTED',
                'retcode' => '10016',
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

        $positionId = (string) ($request['position_id'] ?? $request['broker_position_id'] ?? random_int(700000, 799999));
        $volume = (float) ($request['volume'] ?? $request['close_volume'] ?? 0.10);
        $filled = $this->forcePartialFill && $action === 'PLACE_ORDER' ? max(0.01, round($volume / 2, 2)) : $volume;

        if ($action === 'MODIFY_POSITION_PROTECTION') {
            $this->positions[$positionId] = array_merge($this->positions[$positionId] ?? [
                'ticket' => $positionId,
                'symbol' => $request['symbol'] ?? 'EURUSD',
                'volume' => number_format($volume, 4, '.', ''),
            ], [
                'sl' => $request['stop_loss'] ?? null,
                'tp' => $request['take_profit'] ?? null,
                'source' => 'FAKE_DEMO_BRIDGE',
            ]);
        }

        if ($action === 'CLOSE_POSITION') {
            unset($this->positions[$positionId]);
        }

        if ($action === 'PARTIAL_CLOSE') {
            $existing = (float) ($this->positions[$positionId]['volume'] ?? $volume);
            $remain = max(0, round($existing - (float) ($request['close_volume'] ?? $volume), 4));
            if ($remain <= 0) {
                unset($this->positions[$positionId]);
            } else {
                $this->positions[$positionId]['volume'] = number_format($remain, 4, '.', '');
            }
        }

        if ($action === 'PLACE_ORDER') {
            $this->positions[$positionId] = [
                'ticket' => $positionId,
                'symbol' => $request['symbol'] ?? 'EURUSD',
                'volume' => number_format($filled, 4, '.', ''),
                'sl' => $request['stop_loss'] ?? null,
                'tp' => $request['take_profit'] ?? null,
                'source' => 'FAKE_DEMO_BRIDGE',
            ];
        }

        $retcode = $this->forcePartialFill && $action === 'PLACE_ORDER' ? '10010' : '10009';

        return [
            'check' => $check,
            'send' => [
                'retcode' => $retcode,
                'order' => (string) random_int(800000, 899999),
                'deal' => (string) random_int(900000, 999999),
                'volume' => number_format($filled, 4, '.', ''),
                'price' => $request['price'] ?? '1.10020',
                'comment' => 'FAKE_'.$action,
                'nonce' => $nonce,
                'position_id' => $positionId,
                'sl' => $request['stop_loss'] ?? null,
                'tp' => $request['take_profit'] ?? null,
            ],
            'outcome' => $this->forcePartialFill && $action === 'PLACE_ORDER' ? 'PARTIALLY_FILLED' : 'FILLED',
            'retcode' => $retcode,
            'correlation_id' => $correlationId,
            'position_id' => $positionId,
            'hedging' => true,
            'netting' => false,
            'action' => $action,
            'source' => 'FAKE_DEMO_BRIDGE',
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
        if ($this->positions !== []) {
            return array_values($this->positions);
        }

        return [['ticket' => '710001', 'symbol' => 'EURUSD', 'volume' => '0.10', 'source' => 'FAKE_DEMO_BRIDGE']];
    }
}
