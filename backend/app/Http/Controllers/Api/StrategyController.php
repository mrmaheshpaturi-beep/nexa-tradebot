<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StrategyRequest;
use App\Models\TradingStrategy;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StrategyController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->strategies()->with('settings', 'versions')->paginate()]);
    }

    public function store(StrategyRequest $request): JsonResponse
    {
        $strategy = DB::transaction(function () use ($request): TradingStrategy {
            $this->assertOwnedRiskProfile($request);
            $strategy = $request->user()->strategies()->create([
                ...$request->safe()->except(['configuration', 'change_summary']),
                'auto_trading_enabled' => false,
                'created_by' => $request->user()->id,
            ]);
            $strategy->versions()->create([
                'version' => 1,
                'created_by' => $request->user()->id,
                'configuration' => $request->input('configuration', []),
                'change_summary' => $request->input('change_summary'),
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
        DB::transaction(function () use ($request, $strategy): void {
            $before = $strategy->toArray();
            $attributes = [
                ...$request->safe()->except(['configuration', 'change_summary']),
                'auto_trading_enabled' => false,
            ];
            if ($request->has('configuration')) {
                $attributes['version'] = $strategy->version + 1;
            }
            $strategy->update($attributes);
            if ($request->has('configuration')) {
                $strategy->versions()->create([
                    'version' => $strategy->version,
                    'created_by' => $request->user()->id,
                    'configuration' => $request->input('configuration'),
                    'change_summary' => $request->input('change_summary'),
                ]);
            }
            $this->audit->record('strategy.updated', $strategy, $before, $strategy->toArray(), $request);
        });

        return response()->json(['data' => $strategy->load('versions')]);
    }

    private function assertOwnedRiskProfile(Request $request): void
    {
        if ($request->filled('risk_profile_id')) {
            abort_unless($request->user()->riskProfiles()->whereKey($request->integer('risk_profile_id'))->exists(), 422);
        }
    }
}
