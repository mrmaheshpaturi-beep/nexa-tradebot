<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TradingBridgeException;
use App\Http\Controllers\Controller;
use App\Services\IndicatorEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class IndicatorController extends Controller
{
    public function __construct(
        private readonly IndicatorEngineService $engine,
    ) {}

    public function catalog(): JsonResponse
    {
        return response()->json([
            'data' => [
                'phase' => 6,
                'engine' => 'INDICATOR_ENGINE',
                'read_only' => true,
                'indicators' => $this->engine->catalog(),
                'execution' => [
                    'order_send' => false,
                    'demo' => false,
                    'live' => false,
                ],
            ],
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->engine->health()]);
    }

    public function compute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'indicator' => ['required', 'string', 'max:32'],
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:10', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
            'params' => ['sometimes', 'array'],
            'cache' => ['sometimes', 'boolean'],
        ]);

        return $this->respond(fn () => $this->engine->compute(
            $validated['indicator'],
            $validated['symbol'],
            strtoupper($validated['timeframe'] ?? 'M5'),
            (int) ($validated['count'] ?? 120),
            $validated['params'] ?? [],
            $validated['prefer'] ?? 'auto',
            (bool) ($validated['cache'] ?? true),
        ));
    }

    public function series(Request $request, string $indicator): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:10', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
            'period' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'fast' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'slow' => ['sometimes', 'integer', 'min:2', 'max:400'],
            'signal' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'std_dev' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
            'source' => ['sometimes', 'string', 'in:close,open,high,low'],
            'cache' => ['sometimes', 'boolean'],
        ]);

        $params = array_filter([
            'period' => $validated['period'] ?? null,
            'fast' => $validated['fast'] ?? null,
            'slow' => $validated['slow'] ?? null,
            'signal' => $validated['signal'] ?? null,
            'std_dev' => $validated['std_dev'] ?? null,
            'source' => $validated['source'] ?? null,
        ], fn ($value) => $value !== null);

        return $this->respond(fn () => $this->engine->series(
            $indicator,
            $validated['symbol'],
            strtoupper($validated['timeframe'] ?? 'M5'),
            (int) ($validated['count'] ?? 120),
            $params,
            $validated['prefer'] ?? 'auto',
        ));
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'indicators' => ['required', 'array', 'min:1', 'max:6'],
            'indicators.*' => ['string', 'max:32'],
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:10', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
            'params' => ['sometimes', 'array'],
        ]);

        return $this->respond(fn () => [
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => strtoupper($validated['timeframe'] ?? 'M5'),
            'results' => $this->engine->computeMany(
                $validated['indicators'],
                $validated['symbol'],
                strtoupper($validated['timeframe'] ?? 'M5'),
                (int) ($validated['count'] ?? 120),
                $validated['params'] ?? [],
                $validated['prefer'] ?? 'auto',
            ),
            'read_only' => true,
            'execution' => ['order_send' => false],
        ]);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_INDICATOR',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->safeCode === 'BRIDGE_UNAVAILABLE'
                        || $exception->safeCode === 'BRIDGE_NOT_CONFIGURED'
                        || $exception->safeCode === 'BRIDGE_CIRCUIT_OPEN'
                        || $exception->getMessage() === 'The MT5 read-only bridge is unavailable.'
                        ? 'MT5 DATA UNAVAILABLE'
                        : $exception->getMessage(),
                    'detail_code' => $exception->safeCode,
                ],
            ], $exception->httpStatus);
        }
    }
}
