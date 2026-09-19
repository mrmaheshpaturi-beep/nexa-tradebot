<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Intelligence\Advanced\AdvancedIntelligenceOrchestrator;
use App\Intelligence\Advanced\MarketFeatureEngine;
use App\Intelligence\Advanced\ResearchMemoryService;
use App\Intelligence\AIAnalysisService;
use App\Intelligence\Support\IntelligenceSafety;
use App\Models\IntelligenceAdvancedSnapshot;
use App\Models\IntelligenceMemoryRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 17 Advanced Intelligence APIs — extends Phase 13 routes.
 * Read/analyze only. Mutation endpoints always refuse.
 */
class AdvancedIntelligenceController extends Controller
{
    public function __construct(
        private readonly AdvancedIntelligenceOrchestrator $orchestrator,
        private readonly ResearchMemoryService $memory,
        private readonly AIAnalysisService $ai,
        private readonly MarketFeatureEngine $features,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'data' => array_merge($this->orchestrator->health(), [
                'label' => 'ADVANCED_INTELLIGENCE_ADVISORY',
                'ai_provider_meta' => $this->ai->providerMeta(),
            ]),
        ]);
    }

    public function desk(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->desk($request->user())]);
    }

    public function assess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'string', 'max:16'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
            'candles' => ['nullable', 'array'],
            'htf_candles' => ['nullable', 'array'],
            'mtf_candles' => ['nullable', 'array'],
            'cross_market' => ['nullable', 'array'],
            'evaluations' => ['nullable', 'array'],
            'spread_points' => ['nullable', 'numeric'],
            'spread_cap' => ['nullable', 'numeric'],
            'evidence_buckets' => ['nullable', 'array'],
            'portfolio' => ['nullable', 'array'],
            'execution' => ['nullable', 'array'],
            'session' => ['nullable', 'array'],
            'include_ai' => ['nullable', 'boolean'],
            'record_research' => ['nullable', 'boolean'],
            'feature_as_of_epoch' => ['nullable', 'integer'],
            'feature_observed_epoch' => ['nullable', 'integer'],
            'calendar_provider' => ['nullable', 'string'],
            'news_provider' => ['nullable', 'string'],
        ]);

        $snap = $this->orchestrator->assess($request->user(), $data, $request);

        return response()->json(['data' => $snap], 201);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $rows = IntelligenceAdvancedSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->latest('assessed_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function showSnapshot(Request $request, IntelligenceAdvancedSnapshot $snapshot): JsonResponse
    {
        abort_unless($snapshot->user_id === $request->user()->id, 404);

        return response()->json(['data' => $snapshot->load('assessment')]);
    }

    public function mtf(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candles' => ['required', 'array'],
            'htf_candles' => ['nullable', 'array'],
            'mtf_candles' => ['nullable', 'array'],
        ]);
        $deep = (new \App\Intelligence\Advanced\DeepMarketStructureEngine)->analyze(
            $data['candles'],
            $data['htf_candles'] ?? null,
            $data['mtf_candles'] ?? [],
        );

        return response()->json(['data' => [
            'label' => 'MTF_MATRIX_ADVISORY',
            'mtf' => $deep['mtf'] ?? [],
            'mtf_matrix' => $deep['mtf_matrix'] ?? [],
            'regime' => $deep['regime'] ?? [],
            'structure' => $deep['structure'] ?? [],
            'support_resistance' => $deep['support_resistance'] ?? [],
            'trend' => $deep['trend'] ?? [],
            'momentum' => $deep['momentum'] ?? [],
            'volatility_ext' => $deep['volatility_ext'] ?? [],
            'disclaimer' => 'ADVISORY — MTF matrix does not execute.',
            'safety' => IntelligenceSafety::safetyFlags(),
        ]]);
    }

    public function features(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candles' => ['required', 'array'],
            'feature_as_of_epoch' => ['nullable', 'integer'],
            'feature_observed_epoch' => ['nullable', 'integer'],
        ]);
        $out = $this->features->extract(
            $data['candles'],
            isset($data['feature_as_of_epoch']) ? (int) $data['feature_as_of_epoch'] : null,
            isset($data['feature_observed_epoch']) ? (int) $data['feature_observed_epoch'] : null,
        );

        return response()->json(['data' => $out]);
    }

    public function analogs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candles' => ['required', 'array'],
            'as_of_index' => ['nullable', 'integer'],
        ]);
        $out = (new \App\Intelligence\Advanced\HistoricalAnalogEngine)->find(
            $data['candles'],
            isset($data['as_of_index']) ? (int) $data['as_of_index'] : null,
        );

        return response()->json(['data' => $out]);
    }

    public function suitability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market_quality_status' => ['nullable', 'string'],
            'event_risk' => ['nullable', 'string'],
            'uncertainty_score' => ['nullable', 'numeric'],
            'mtf_agreement' => ['nullable', 'string'],
            'governance_filter_status' => ['nullable', 'string'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
        ]);
        $out = (new \App\Intelligence\Advanced\SuitabilityAnalysisEngine)->analyze($data);

        return response()->json(['data' => $out]);
    }

    public function memory(Request $request): JsonResponse
    {
        $rows = IntelligenceMemoryRecord::query()
            ->where('user_id', $request->user()->id)
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => [
            'label' => 'INTELLIGENCE_RESEARCH_MEMORY',
            'immutable' => true,
            'records' => $rows,
            'disclaimer' => 'Append-only research memory — no mutation path.',
        ]]);
    }

    public function postTradeMemory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
            'payload' => ['required', 'array'],
            'assessment_public_id' => ['nullable', 'string'],
        ]);
        $payload = $data['payload'];
        $payload['mode'] = $data['mode'] ?? 'ADVISORY';
        $assessmentId = null;
        if (! empty($data['assessment_public_id'])) {
            $a = \App\Models\IntelligenceAssessment::query()
                ->where('public_id', $data['assessment_public_id'])
                ->where('user_id', $request->user()->id)
                ->first();
            $assessmentId = $a?->id;
        }
        $row = $this->memory->record(
            $request->user(),
            'POST_TRADE',
            $payload,
            $data['symbol'] ?? null,
            $assessmentId,
            $request,
        );

        return response()->json(['data' => $row], 201);
    }

    public function evidence(Request $request): JsonResponse
    {
        $snap = IntelligenceAdvancedSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->latest('assessed_at')
            ->first();

        return response()->json(['data' => [
            'label' => 'EVIDENCE_QUALITY_ADVISORY',
            'latest_snapshot_public_id' => $snap?->public_id,
            'uncertainty' => $snap?->uncertainty,
            'evidence_buckets' => $snap?->payload['evidence_buckets'] ?? null,
            'scoring_separation' => $snap?->scoring_separation,
            'safety' => IntelligenceSafety::safetyFlags(),
        ]]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,system'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);
        $out = $this->ai->chat($request->user(), $data['messages'], $request);

        return response()->json(['data' => array_merge($out, [
            'label' => 'ADVISORY_CHAT_READ_ONLY',
            'mutation_tools_available' => false,
            'disclaimer' => 'ADVISORY labeled chat — cannot mutate risk/config/approval/deployment or call order_send.',
        ])]);
    }

    public function refuseMutate(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', 'unknown');

        return response()->json(['data' => $this->orchestrator->refuseMutation($action)], 403);
    }
}
