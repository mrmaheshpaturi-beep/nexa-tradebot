<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingStrategy;
use App\Services\StrategyEngineService;
use App\Services\StrategyPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyEngineController extends Controller
{
    public function __construct(
        private readonly StrategyEngineService $engine,
        private readonly StrategyPerformanceService $performance,
    ) {}

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => [
            'phase' => 7,
            'plugins' => $this->engine->catalog(),
            'upload_allowed' => false,
            'execution' => [
                'order_send' => false,
                'demo_execution' => false,
                'live_execution' => false,
            ],
        ]]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->engine->health()]);
    }

    public function evaluate(Request $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:20'],
            'timeframe' => ['nullable', 'string', 'max:10'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
            'create_signal' => ['sometimes', 'boolean'],
        ]);

        $result = $this->engine->evaluateStrategy(
            $request->user(),
            $strategy,
            $validated['symbol'] ?? null,
            $validated['timeframe'] ?? null,
            $validated['prefer'] ?? 'simulation',
            $validated['create_signal'] ?? true,
        );

        return response()->json(['data' => $result]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
        ]);

        return response()->json(['data' => $this->engine->evaluateAll(
            $request->user(),
            $validated['prefer'] ?? 'simulation',
        )]);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['nullable', 'string', 'max:10'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
        ]);

        return response()->json(['data' => $this->engine->scan(
            $request->user(),
            $validated['symbol'],
            $validated['timeframe'] ?? 'M5',
            $validated['prefer'] ?? 'simulation',
        )]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
        ]);

        return response()->json(['data' => $this->engine->matrix(
            $request->user(),
            $validated['prefer'] ?? 'simulation',
        )]);
    }

    public function confluence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'timeframe' => ['nullable', 'string', 'max:10'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
        ]);
        $scan = $this->engine->scan(
            $request->user(),
            $validated['symbol'],
            $validated['timeframe'] ?? 'M5',
            $validated['prefer'] ?? 'simulation',
        );

        return response()->json(['data' => [
            'phase' => 7,
            'symbol' => $scan['symbol'],
            'timeframe' => $scan['timeframe'],
            'confluence' => $scan['confluence'],
            'evaluations' => $scan['evaluations'],
            'disclaimer' => $scan['disclaimer'],
            'execution' => $scan['execution'],
        ]]);
    }

    public function performance(Request $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->performance->summarize($strategy)]);
    }
}
