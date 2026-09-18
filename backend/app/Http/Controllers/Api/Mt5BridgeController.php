<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TradingBridgeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mt5ReadRequest;
use App\Models\Mt5AccountMapping;
use App\Models\Mt5BridgeConnection;
use App\Models\Mt5ReconciliationRun;
use App\Services\AuditService;
use App\Services\Mt5ReadModelService;
use App\Services\TradingBridgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Mt5BridgeController extends Controller
{
    public function __construct(
        private readonly TradingBridgeClient $bridge,
        private readonly Mt5ReadModelService $readModels,
        private readonly AuditService $audit,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $connection = $request->user()->mt5BridgeConnections()->with('accountMappings.brokerAccount')->latest()->first();

        return response()->json(['data' => [
            'configured' => $this->bridge->configured(),
            'mode' => 'READ_ONLY',
            'environment' => 'DEMO',
            'execution_available' => false,
            'broker_transmission' => false,
            'allow_demo_execution' => false,
            'allow_live_execution' => false,
            'circuit_state' => Cache::get('mt5_bridge:last_state', 'UNKNOWN'),
            'connection' => $connection,
        ]]);
    }

    public function proxyHealth(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('health', cacheable: true);
    }

    public function proxyTerminal(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('terminal');
    }

    public function proxyAccount(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('account');
    }

    public function proxySymbols(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('symbols', cacheable: true);
    }

    public function proxySymbol(Mt5ReadRequest $request, string $symbol): JsonResponse
    {
        return $this->proxy('symbols/'.strtoupper($symbol), cacheable: true);
    }

    public function proxyQuote(Mt5ReadRequest $request, string $symbol): JsonResponse
    {
        return $this->proxy('quotes/'.strtoupper($symbol), cacheable: true);
    }

    public function proxyCandles(Mt5ReadRequest $request, string $symbol): JsonResponse
    {
        return $this->proxy('candles/'.strtoupper($symbol), $request->only('timeframe', 'count'), cacheable: true);
    }

    public function proxyPositions(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('positions');
    }

    public function proxyOrders(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('orders');
    }

    public function proxyHistoryOrders(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('history/orders', $request->only('date_from', 'date_to', 'limit'));
    }

    public function proxyHistoryDeals(Mt5ReadRequest $request): JsonResponse
    {
        return $this->proxy('history/deals', $request->only('date_from', 'date_to', 'limit'));
    }

    public function connections(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->mt5BridgeConnections()
                ->with(['accountMappings.brokerAccount', 'aliases.instrument'])
                ->latest()
                ->paginate(),
        ]);
    }

    public function storeConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $connection = DB::transaction(function () use ($request, $validated): Mt5BridgeConnection {
            $connection = $request->user()->mt5BridgeConnections()->create([
                'name' => $validated['name'],
                'mode' => 'REAL',
                'environment' => 'DEMO',
                'status' => $this->bridge->configured() ? 'UNTESTED' : 'UNCONFIGURED',
                'is_enabled' => $validated['is_enabled'] ?? false,
                'metadata' => ['read_only' => true],
            ]);
            $this->audit->record('mt5_bridge.connection_created', $connection, [], $connection->toArray(), $request);

            return $connection;
        });

        return response()->json(['data' => $connection], 201);
    }

    public function testConnection(Request $request, Mt5BridgeConnection $connection): JsonResponse
    {
        $this->assertOwnedConnection($request, $connection);
        $payload = $this->bridge->get('health', [], cacheable: true);
        $connection->update([
            'status' => data_get($payload, 'data.connected', false) ? 'CONNECTED' : 'DISCONNECTED',
            'last_tested_at' => now(),
            'last_connected_at' => data_get($payload, 'data.connected', false) ? now() : $connection->last_connected_at,
            'last_error_code' => null,
        ]);
        $this->audit->record('mt5_bridge.connection_tested', $connection, [], [
            'status' => $connection->status,
            'freshness' => data_get($payload, 'meta.freshness'),
        ], $request);

        return response()->json(['data' => [
            'connection' => $connection->fresh(),
            'bridge' => $payload,
        ]]);
    }

    public function syncConnection(Request $request, Mt5BridgeConnection $connection): JsonResponse
    {
        $this->assertOwnedConnection($request, $connection);
        abort_unless($connection->is_enabled, 422, 'The MT5 read connection is disabled.');
        $summary = $this->readModels->sync($connection);
        $this->audit->record('mt5_bridge.sync_completed', $connection, [], $summary, $request);

        return response()->json(['data' => $summary]);
    }

    public function reconcileMapping(Request $request, Mt5AccountMapping $mapping): JsonResponse
    {
        $this->assertOwnedMapping($request, $mapping);
        $run = $this->readModels->reconcile($mapping, $request->user()->id);
        $this->audit->record('mt5_bridge.reconciliation_completed', $run, [], [
            'status' => $run->status,
            'matched_count' => $run->matched_count,
            'mismatch_count' => $run->mismatch_count,
        ], $request);

        return response()->json(['data' => $run->load('items')]);
    }

    public function mappingPositions(Request $request, Mt5AccountMapping $mapping): JsonResponse
    {
        $this->assertOwnedMapping($request, $mapping);

        return response()->json(['data' => $mapping->positions()->latest('last_seen_at')->paginate()]);
    }

    public function mappingOrders(Request $request, Mt5AccountMapping $mapping): JsonResponse
    {
        $this->assertOwnedMapping($request, $mapping);

        return response()->json(['data' => $mapping->orders()->latest('last_seen_at')->paginate()]);
    }

    public function mappingDeals(Request $request, Mt5AccountMapping $mapping): JsonResponse
    {
        $this->assertOwnedMapping($request, $mapping);

        return response()->json(['data' => $mapping->deals()->latest('executed_at')->paginate()]);
    }

    public function reconciliationRuns(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Mt5ReconciliationRun::query()
                ->where('user_id', $request->user()->id)
                ->with(['accountMapping.brokerAccount', 'items'])
                ->latest('started_at')
                ->paginate(),
        ]);
    }

    public function reconciliationRun(Request $request, Mt5ReconciliationRun $run): JsonResponse
    {
        abort_unless($run->user_id === $request->user()->id, 404);

        return response()->json(['data' => $run->load(['items', 'accountMapping.brokerAccount'])]);
    }

    private function proxy(string $path, array $query = [], bool $cacheable = false): JsonResponse
    {
        try {
            return response()->json($this->bridge->get($path, $query, $cacheable));
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }

    private function assertOwnedConnection(Request $request, Mt5BridgeConnection $connection): void
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
    }

    private function assertOwnedMapping(Request $request, Mt5AccountMapping $mapping): void
    {
        abort_unless($mapping->connection?->user_id === $request->user()->id, 404);
    }
}
