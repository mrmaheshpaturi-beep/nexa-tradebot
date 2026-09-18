<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StrategyRequest;
use App\Models\TradingStrategy;
use App\Services\AuditService;
use App\Strategies\StrategyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StrategyController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StrategyRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->strategies()->with('settings', 'versions')->paginate()]);
    }

    public function show(Request $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);
        $plugin = $strategy->plugin_key && $this->registry->has($strategy->plugin_key)
            ? collect($this->registry->catalog())->firstWhere('key', $strategy->plugin_key)
            : null;

        return response()->json(['data' => [
            ...$strategy->load(['settings', 'versions', 'riskProfile'])->toArray(),
            'plugin' => $plugin,
            'auto_trading_enabled' => false,
            'execution' => ['order_send' => false, 'broker_auto_trading' => false],
        ]]);
    }

    public function store(StrategyRequest $request): JsonResponse
    {
        $strategy = DB::transaction(function () use ($request): TradingStrategy {
            $this->assertOwnedRiskProfile($request);
            $pluginKey = $request->input('plugin_key');
            if ($pluginKey) {
                abort_unless($this->registry->has($pluginKey), 422, 'Unknown strategy plugin.');
            }
            $strategy = $request->user()->strategies()->create([
                ...$request->safe()->except(['configuration', 'change_summary']),
                'auto_trading_enabled' => false,
                'auto_simulation' => $request->boolean('auto_simulation', false),
                'plugin_key' => $pluginKey,
                'evaluation_mode' => $request->input('evaluation_mode', 'ON_CANDLE_CLOSE'),
                'higher_timeframes' => $request->input('higher_timeframes', ['M15', 'H1']),
                'created_by' => $request->user()->id,
            ]);
            $strategy->versions()->create([
                'version' => 1,
                'created_by' => $request->user()->id,
                'configuration' => $request->input('configuration', [
                    'plugin_key' => $pluginKey,
                    'parameters' => $request->input('parameters', []),
                ]),
                'change_summary' => $request->input('change_summary', 'Initial configuration'),
            ]);
            $this->audit->record('strategy.created', $strategy, [], $strategy->toArray(), $request);

            return $strategy;
        });

        return response()->json(['data' => $strategy->refresh()->load('versions')], 201);
    }

    public function update(StrategyRequest $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);
        $this->assertOwnedRiskProfile($request);
        if ($request->filled('plugin_key')) {
            abort_unless($this->registry->has((string) $request->input('plugin_key')), 422, 'Unknown strategy plugin.');
        }
        DB::transaction(function () use ($request, $strategy): void {
            $before = $strategy->toArray();
            $attributes = [
                ...$request->safe()->except(['configuration', 'change_summary']),
                'auto_trading_enabled' => false,
            ];
            if ($request->has('auto_simulation')) {
                $attributes['auto_simulation'] = $request->boolean('auto_simulation');
            }
            if ($request->has('configuration') || $request->has('parameters') || $request->has('plugin_key')) {
                $attributes['version'] = $strategy->version + 1;
            }
            $strategy->update($attributes);
            if ($request->has('configuration') || $request->has('parameters') || $request->has('plugin_key')) {
                $strategy->versions()->create([
                    'version' => $strategy->version,
                    'created_by' => $request->user()->id,
                    'configuration' => $request->input('configuration', [
                        'plugin_key' => $strategy->plugin_key,
                        'parameters' => $strategy->parameters,
                    ]),
                    'change_summary' => $request->input('change_summary', 'Configuration update'),
                ]);
            }
            $this->audit->record('strategy.updated', $strategy, $before, $strategy->toArray(), $request);
        });

        return response()->json(['data' => $strategy->load('versions')]);
    }

    public function enable(Request $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);
        $strategy->update([
            'enabled' => true,
            'status' => 'ACTIVE',
            'auto_trading_enabled' => false,
        ]);

        return response()->json(['data' => $strategy->refresh()]);
    }

    public function disable(Request $request, TradingStrategy $strategy): JsonResponse
    {
        abort_unless($strategy->user_id === $request->user()->id, 404);
        $strategy->update(['enabled' => false, 'status' => 'DRAFT']);

        return response()->json(['data' => $strategy->refresh()]);
    }

    private function assertOwnedRiskProfile(Request $request): void
    {
        if ($request->filled('risk_profile_id')) {
            abort_unless($request->user()->riskProfiles()->whereKey($request->integer('risk_profile_id'))->exists(), 422);
        }
    }
}
