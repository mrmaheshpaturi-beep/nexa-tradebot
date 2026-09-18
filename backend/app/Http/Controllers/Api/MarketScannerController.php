<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScannerConfig;
use App\Models\ScannerRun;
use App\Models\SignalCandidate;
use App\Services\AlertPipelineService;
use App\Services\MarketScannerEngineService;
use App\Services\SignalOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 Market Scanner + Signal Orchestrator APIs.
 * Scanning / candidates only — never broker execution.
 */
class MarketScannerController extends Controller
{
    public function __construct(
        private readonly MarketScannerEngineService $scanner,
        private readonly SignalOrchestratorService $orchestrator,
        private readonly AlertPipelineService $alerts,
    ) {}

    public function health(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scanner->health($request->user())]);
    }

    public function universe(): JsonResponse
    {
        return response()->json(['data' => array_merge($this->scanner->defaultUniverse(), [
            'phase' => 8,
            'execution' => [
                'order_send' => false,
                'broker_routing' => false,
            ],
        ])]);
    }

    public function board(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'symbol', 'timeframe', 'direction', 'plugin_key', 'limit']);

        return response()->json(['data' => $this->scanner->board($request->user(), $filters)]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trigger' => ['nullable', 'in:MANUAL,ON_INTERVAL,ON_CANDLE_CLOSE'],
            'config_id' => ['nullable', 'integer'],
            'symbols' => ['nullable', 'array', 'max:40'],
            'symbols.*' => ['string', 'max:20'],
            'timeframes' => ['nullable', 'array', 'max:12'],
            'timeframes.*' => ['string', 'max:10'],
            'strategy_ids' => ['nullable', 'array', 'max:50'],
            'strategy_ids.*' => ['integer'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
            'create_signals' => ['sometimes', 'boolean'],
            'create_candidates' => ['sometimes', 'boolean'],
        ]);

        $result = $this->scanner->runScan($request->user(), [
            'trigger' => $validated['trigger'] ?? 'MANUAL',
            'config_id' => $validated['config_id'] ?? null,
            'symbols' => $validated['symbols'] ?? null,
            'timeframes' => $validated['timeframes'] ?? null,
            'strategy_ids' => $validated['strategy_ids'] ?? null,
            'prefer' => $validated['prefer'] ?? 'simulation',
            'create_signals' => $validated['create_signals'] ?? null,
            'create_candidates' => $validated['create_candidates'] ?? null,
        ]);

        return response()->json(['data' => $result]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['nullable'],
            'timeframes' => ['nullable'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
        ]);

        $symbols = $validated['symbols'] ?? null;
        if (is_string($symbols)) {
            $symbols = array_values(array_filter(array_map('trim', explode(',', $symbols))));
        }
        $timeframes = $validated['timeframes'] ?? null;
        if (is_string($timeframes)) {
            $timeframes = array_values(array_filter(array_map('trim', explode(',', $timeframes))));
        }

        return response()->json(['data' => $this->scanner->matrix(
            $request->user(),
            is_array($symbols) ? $symbols : null,
            is_array($timeframes) ? $timeframes : null,
            $validated['prefer'] ?? 'simulation',
        )]);
    }

    public function configs(Request $request): JsonResponse
    {
        $rows = ScannerConfig::query()->where('user_id', $request->user()->id)->orderBy('id')->get();

        return response()->json(['data' => ['phase' => 8, 'configs' => $rows]]);
    }

    public function upsertConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'symbols' => ['required', 'array', 'min:1', 'max:40'],
            'symbols.*' => ['string', 'max:20'],
            'timeframes' => ['required', 'array', 'min:1', 'max:12'],
            'timeframes.*' => ['string', 'max:10'],
            'plugin_keys' => ['nullable', 'array'],
            'strategy_ids' => ['nullable', 'array'],
            'trigger_mode' => ['nullable', 'in:MANUAL,ON_INTERVAL,ON_CANDLE_CLOSE'],
            'interval_seconds' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'enabled' => ['sometimes', 'boolean'],
            'prefer' => ['nullable', 'in:auto,simulation,bridge,mock'],
            'create_signals' => ['sometimes', 'boolean'],
            'create_candidates' => ['sometimes', 'boolean'],
        ]);

        $config = null;
        if (! empty($validated['id'])) {
            $config = ScannerConfig::query()->where('user_id', $request->user()->id)->whereKey($validated['id'])->firstOrFail();
            $config->version = (int) $config->version + 1;
        } else {
            $config = new ScannerConfig(['user_id' => $request->user()->id, 'version' => 1]);
        }

        $config->fill([
            'name' => $validated['name'] ?? $config->name ?? 'Universe',
            'symbols' => array_values(array_map('strtoupper', $validated['symbols'])),
            'timeframes' => array_values(array_map('strtoupper', $validated['timeframes'])),
            'plugin_keys' => $validated['plugin_keys'] ?? $config->plugin_keys,
            'strategy_ids' => $validated['strategy_ids'] ?? $config->strategy_ids,
            'trigger_mode' => $validated['trigger_mode'] ?? $config->trigger_mode ?? 'MANUAL',
            'interval_seconds' => $validated['interval_seconds'] ?? $config->interval_seconds ?? 300,
            'enabled' => $validated['enabled'] ?? $config->enabled ?? true,
            'prefer' => $validated['prefer'] ?? $config->prefer ?? 'simulation',
            'create_signals' => $validated['create_signals'] ?? $config->create_signals ?? true,
            'create_candidates' => $validated['create_candidates'] ?? $config->create_candidates ?? true,
            'metadata' => ['phase' => 8],
        ]);
        $config->user_id = $request->user()->id;
        $config->save();

        return response()->json(['data' => $config]);
    }

    public function runs(Request $request): JsonResponse
    {
        $rows = ScannerRun::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(min(50, max(1, (int) $request->query('limit', 20))))
            ->get();

        return response()->json(['data' => ['phase' => 8, 'runs' => $rows]]);
    }

    public function queue(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'symbol', 'timeframe', 'direction', 'plugin_key', 'limit']);

        return response()->json(['data' => $this->orchestrator->queue($request->user(), $filters)]);
    }

    public function showCandidate(Request $request, SignalCandidate $candidate): JsonResponse
    {
        abort_unless($candidate->user_id === $request->user()->id, 404);
        $candidate->load(['strategy', 'signal', 'run']);

        return response()->json(['data' => [
            'candidate' => $candidate,
            'execution' => [
                'order_send' => false,
                'broker_routing' => false,
                'mode' => 'CANDIDATES_ONLY',
            ],
            'disclaimer' => 'Candidate is not an order. Scores are not win probabilities.',
        ]]);
    }

    public function dismiss(Request $request, SignalCandidate $candidate): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->dismiss($request->user(), $candidate)]);
    }

    public function invalidate(Request $request, SignalCandidate $candidate): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->orchestrator->invalidate(
            $request->user(),
            $candidate,
            $validated['reason'] ?? 'MANUAL',
        )]);
    }

    public function markSimulate(Request $request, SignalCandidate $candidate): JsonResponse
    {
        $updated = $this->orchestrator->markForSimulate($request->user(), $candidate);

        return response()->json(['data' => [
            'candidate' => $updated,
            'simulate_target' => 'SIMULATION_ONLY',
            'mt5_execution' => false,
            'execution' => [
                'order_send' => false,
                'demo_execution' => false,
                'live_execution' => false,
            ],
        ]]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $rows = \App\Models\ScannerAlertEvent::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(min(100, max(1, (int) $request->query('limit', 30))))
            ->get();

        return response()->json(['data' => [
            'phase' => 8,
            'alerts' => $rows,
            'pipeline' => $this->alerts->health(),
        ]]);
    }

    public function expireDue(Request $request): JsonResponse
    {
        $count = $this->orchestrator->expireDue($request->user());

        return response()->json(['data' => ['expired' => $count, 'phase' => 8]]);
    }
}
