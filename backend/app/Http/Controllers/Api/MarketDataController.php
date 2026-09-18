<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TradingBridgeException;
use App\Http\Controllers\Controller;
use App\Models\MarketSnapshot;
use App\Services\MarketDataEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketDataController extends Controller
{
    public function __construct(private readonly MarketDataEngineService $engine) {}

    public function snapshot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['sometimes', 'string', 'max:200'],
            'candle_symbol' => ['sometimes', 'string', 'max:20'],
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'candle_count' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
            'persist' => ['sometimes', 'boolean'],
        ]);

        try {
            $symbols = isset($validated['symbols'])
                ? array_values(array_filter(array_map('trim', explode(',', strtoupper($validated['symbols'])))))
                : null;
            $data = $this->engine->snapshot(
                $symbols,
                strtoupper($validated['candle_symbol'] ?? 'EURUSD'),
                strtoupper($validated['timeframe'] ?? 'M5'),
                (int) ($validated['candle_count'] ?? 60),
                $validated['prefer'] ?? 'auto',
                (bool) ($validated['persist'] ?? true),
            );

            return response()->json(['data' => $data]);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }

    public function quotes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['sometimes', 'string', 'max:200'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);
        $symbols = isset($validated['symbols'])
            ? array_values(array_filter(array_map('trim', explode(',', strtoupper($validated['symbols'])))))
            : null;

        try {
            return response()->json([
                'data' => $this->engine->quotes($symbols, $validated['prefer'] ?? 'auto'),
            ]);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }

    public function candles(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        try {
            return response()->json([
                'data' => $this->engine->candles(
                    strtoupper($symbol),
                    strtoupper($validated['timeframe'] ?? 'M5'),
                    (int) ($validated['count'] ?? 100),
                    $validated['prefer'] ?? 'auto',
                ),
            ]);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }

    public function symbols(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        try {
            return response()->json([
                'data' => $this->engine->symbols($validated['prefer'] ?? 'auto'),
            ]);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }

    public function latestPersisted(Request $request): JsonResponse
    {
        $snapshot = MarketSnapshot::query()->latest('generated_at')->first();

        return response()->json([
            'data' => $snapshot,
        ]);
    }

    public function extensionHooks(): JsonResponse
    {
        return response()->json([
            'data' => [
                'phase' => 5,
                'market_data_engine' => 'READY',
                'phase_6_indicator_engine' => [
                    'status' => 'PENDING',
                    'consumes' => ['market_snapshot.quotes', 'market_snapshot.candles'],
                    'note' => 'Phase 6 will attach indicator series to snapshot candles without broker writes.',
                ],
                'phase_7_strategies' => [
                    'status' => 'PENDING',
                    'consumes' => ['market_snapshot', 'phase_6_indicators'],
                    'note' => 'Phase 7 strategies remain gated behind SIMULATION execution controls.',
                ],
                'execution' => [
                    'read_only' => true,
                    'order_send' => false,
                    'broker_transmission' => false,
                ],
            ],
        ]);
    }
}
