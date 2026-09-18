<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TradingBridgeException;
use App\Http\Controllers\Controller;
use App\Models\MarketSnapshot;
use App\Services\AuditService;
use App\Services\MarketDataEngineService;
use App\Services\MarketSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketDataController extends Controller
{
    public function __construct(
        private readonly MarketDataEngineService $engine,
        private readonly MarketSessionService $sessions,
        private readonly AuditService $audit,
    ) {}

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

        return $this->respond(function () use ($validated) {
            $symbols = isset($validated['symbols'])
                ? array_values(array_filter(array_map('trim', explode(',', strtoupper($validated['symbols'])))))
                : null;

            return $this->engine->snapshot(
                $symbols,
                strtoupper($validated['candle_symbol'] ?? 'EURUSD'),
                strtoupper($validated['timeframe'] ?? 'M5'),
                (int) ($validated['candle_count'] ?? 60),
                $validated['prefer'] ?? 'auto',
                (bool) ($validated['persist'] ?? true),
            );
        });
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

        return $this->respond(fn () => $this->engine->quotes($symbols, $validated['prefer'] ?? 'auto'));
    }

    public function quote(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        return $this->respond(function () use ($symbol, $validated) {
            $quote = $this->engine->quote(strtoupper($symbol), $validated['prefer'] ?? 'auto');
            abort_if($quote === null, 404, 'Quote not found.');

            return $quote;
        });
    }

    public function candles(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
            'closed_only' => ['sometimes', 'boolean'],
        ]);

        return $this->respond(fn () => $this->engine->candles(
            strtoupper($symbol),
            strtoupper($validated['timeframe'] ?? 'M5'),
            (int) ($validated['count'] ?? 100),
            $validated['prefer'] ?? 'auto',
            (bool) ($validated['closed_only'] ?? false),
        ));
    }

    public function closedCandles(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'timeframe' => ['sometimes', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        return $this->respond(fn () => $this->engine->getClosedCandles(
            strtoupper($symbol),
            strtoupper($validated['timeframe'] ?? 'M5'),
            (int) ($validated['count'] ?? 100),
            $validated['prefer'] ?? 'auto',
        ));
    }

    public function symbols(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        return $this->respond(fn () => $this->engine->symbols($validated['prefer'] ?? 'auto'));
    }

    public function sessions(): JsonResponse
    {
        return response()->json(['data' => $this->sessions->sessions()]);
    }

    public function status(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        return $this->respond(fn () => $this->engine->symbolStatus(strtoupper($symbol), $validated['prefer'] ?? 'auto'));
    }

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->engine->health()]);
    }

    public function latestPersisted(): JsonResponse
    {
        return response()->json([
            'data' => MarketSnapshot::query()->latest('generated_at')->first(),
        ]);
    }

    public function monitored(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json(['data' => ['symbols' => $this->engine->monitoredSymbols()]]);
        }

        $validated = $request->validate([
            'symbols' => ['required', 'array', 'min:1', 'max:50'],
            'symbols.*' => ['string', 'max:20'],
        ]);
        $symbols = $this->engine->setMonitoredSymbols($validated['symbols']);
        $this->audit->record('market_data.monitored_symbols_updated', null, [], ['symbols' => $symbols], $request);

        return response()->json(['data' => ['symbols' => $symbols]]);
    }

    public function syncSymbols(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);
        $result = $this->engine->syncSymbols($validated['prefer'] ?? 'auto');
        $this->audit->record('market_data.symbols_synced', null, [], $result, $request);

        return response()->json(['data' => $result]);
    }

    public function backfill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['required', 'string', 'in:M1,M5,M15,M30,H1,H4,D1'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);
        $result = $this->engine->backfill(
            strtoupper($validated['symbol']),
            strtoupper($validated['timeframe']),
            (int) ($validated['count'] ?? 100),
            $validated['prefer'] ?? 'auto',
        );
        $this->audit->record('market_data.backfill', null, [], $result, $request);

        return response()->json(['data' => $result]);
    }

    public function qualityCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['sometimes', 'string', 'in:auto,bridge,simulation'],
        ]);

        return $this->respond(fn () => $this->engine->qualityCheck($validated['prefer'] ?? 'auto'));
    }

    public function extensionHooks(): JsonResponse
    {
        return response()->json([
            'data' => [
                'phase' => 6,
                'market_data_engine' => 'READY',
                'indicator_engine' => 'READY',
                'phase_6_indicator_engine' => [
                    'status' => 'READY',
                    'contract' => 'GET /api/v1/indicators/{indicator}/series?symbol=…',
                    'helper' => 'IndicatorEngineService::compute()',
                    'consumes' => ['MarketDataEngineService::getClosedCandles', 'data_quality_gate'],
                    'apis' => [
                        'catalog' => '/api/v1/indicators/catalog',
                        'compute' => 'POST /api/v1/indicators/compute',
                        'series' => '/api/v1/indicators/{indicator}/series',
                        'batch' => 'POST /api/v1/indicators/batch',
                        'health' => '/api/v1/indicators/health',
                    ],
                    'note' => 'Phase 6 attaches indicator series to closed candles without broker writes.',
                ],
                'phase_7_strategies' => [
                    'status' => 'PENDING',
                    'consumes' => ['market_snapshot', 'data_quality_gate', 'phase_6_indicators'],
                    'note' => 'Phase 7 strategies remain gated behind SIMULATION execution controls and quality gate.',
                ],
                'execution' => [
                    'read_only' => true,
                    'order_send' => false,
                    'broker_transmission' => false,
                ],
            ],
        ]);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (TradingBridgeException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->safeCode,
                    'message' => $exception->getMessage() === 'The MT5 read-only bridge is unavailable.'
                        || $exception->safeCode === 'BRIDGE_UNAVAILABLE'
                        || $exception->safeCode === 'BRIDGE_NOT_CONFIGURED'
                        || $exception->safeCode === 'BRIDGE_CIRCUIT_OPEN'
                        ? 'MT5 DATA UNAVAILABLE'
                        : $exception->getMessage(),
                    'detail_code' => $exception->safeCode,
                ],
            ], $exception->httpStatus);
        }
    }
}
