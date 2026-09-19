<?php

namespace App\Contracts;

use App\Models\BrokerAccount;

interface DemoBridgeClient
{
    /** Fresh account snapshot including trade_mode / login / server. */
    public function verifyAccount(BrokerAccount $account): array;

    /** Fresh quote for symbol from DEMO bridge. */
    public function freshQuote(string $symbol): array;

    /** Fresh symbol specification. */
    public function freshSymbolSpec(string $symbol): array;

    /**
     * order_check then (sole authorized) order_send on the bridge.
     *
     * @param  array<string,mixed>  $request
     * @return array{check:array,send:?array,outcome:string,retcode:?string,correlation_id:string}
     */
    public function checkAndSend(array $request, string $idempotencyKey, string $nonce, string $correlationId): array;

    /**
     * DEMO-only position protection modify (SL/TP). Routes through authorized order_send.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function modifyPositionProtection(array $request, string $idempotencyKey, string $nonce, string $correlationId): array;

    /**
     * DEMO-only full close. Routes through authorized order_send.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function closePosition(array $request, string $idempotencyKey, string $nonce, string $correlationId): array;

    /**
     * DEMO-only partial close. Routes through authorized order_send.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function partialClose(array $request, string $idempotencyKey, string $nonce, string $correlationId): array;

    /**
     * DEMO-only cancel of application-owned pending order.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function cancelPendingOrder(array $request, string $idempotencyKey, string $nonce, string $correlationId): array;

    public function syncOrders(): array;

    public function syncDeals(): array;

    public function syncPositions(): array;
}
