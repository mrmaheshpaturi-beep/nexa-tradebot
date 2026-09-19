<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Intelligence\AIAnalysisService;
use App\Intelligence\ConfidenceCalibration;
use App\Intelligence\IntelligenceJobQueue;
use App\Intelligence\Providers\ProviderResolver;
use App\Intelligence\Support\EvidenceLabels;
use App\Intelligence\TradeIntelligenceEngineService;
use App\Intelligence\UsageMeter;
use App\Models\IntelligenceAssessment;
use App\Models\IntelligenceCalibrationSample;
use App\Models\IntelligenceOpportunity;
use App\Models\IntelligenceSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceController extends Controller
{
    public function __construct(
        private readonly TradeIntelligenceEngineService $engine,
        private readonly AIAnalysisService $ai,
        private readonly IntelligenceJobQueue $queue,
        private readonly UsageMeter $usage,
        private readonly ProviderResolver $providers,
        private readonly ConfidenceCalibration $calibration,
        private readonly AuditService $audit,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'data' => array_merge($this->engine->health(), [
                'phase' => 13,
                'label' => 'TRADE_INTELLIGENCE_ADVISORY',
            ]),
        ]);
    }

    public function desk(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = IntelligenceAssessment::query()
            ->where('user_id', $user->id)
            ->latest('assessed_at')
            ->limit(10)
            ->get();
        $ops = IntelligenceOpportunity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('rank_score')
            ->limit(20)
            ->get();

        return response()->json(['data' => [
            'label' => 'AI_TRADE_DESK_ADVISORY',
            'disclaimer' => 'ADVISORY / SHADOW only — never implies live execution.',
            'health' => $this->engine->health(),
            'pulse' => $this->engine->marketPulse($user),
            'recent_assessments' => $latest,
            'top_opportunities' => $ops,
            'safety' => $this->engine->health(),
        ]]);
    }

    public function assess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'string', 'max:16'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
            'candles' => ['nullable', 'array'],
            'htf_candles' => ['nullable', 'array'],
            'evaluations' => ['nullable', 'array'],
            'spread_points' => ['nullable', 'numeric'],
            'spread_cap' => ['nullable', 'numeric'],
            'evidence_buckets' => ['nullable', 'array'],
            'include_ai' => ['nullable', 'boolean'],
            'calendar_provider' => ['nullable', 'string', 'max:64'],
            'news_provider' => ['nullable', 'string', 'max:64'],
        ]);

        $assessment = $this->engine->assess($request->user(), $data, $request);

        return response()->json(['data' => $assessment->load('aiAnalyses')], 201);
    }

    public function assessments(Request $request): JsonResponse
    {
        $rows = IntelligenceAssessment::query()
            ->where('user_id', $request->user()->id)
            ->latest('assessed_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function showAssessment(Request $request, IntelligenceAssessment $assessment): JsonResponse
    {
        abort_unless($assessment->user_id === $request->user()->id, 404);

        return response()->json(['data' => $assessment->load(['aiAnalyses', 'opportunities'])]);
    }

    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
            'symbols' => ['nullable', 'array'],
            'symbols.*' => ['string', 'max:64'],
            'candles_by_symbol' => ['nullable', 'array'],
        ]);
        $mode = $data['mode'] ?? 'ADVISORY';
        $symbols = $data['symbols'] ?? ['EURUSD', 'GBPUSD', 'USDJPY'];
        $inputs = [];
        foreach ($symbols as $sym) {
            $inputs[] = [
                'symbol' => $sym,
                'candles' => $data['candles_by_symbol'][$sym] ?? $this->synthCandles(),
                'mode' => $mode,
            ];
        }
        $ops = $this->engine->opportunityBoard($request->user(), $inputs, $mode, $request);

        return response()->json(['data' => [
            'label' => 'OPPORTUNITY_BOARD_ADVISORY',
            'mode' => $mode,
            'opportunities' => $ops,
            'disclaimer' => 'ADVISORY / SHADOW — does not execute trades.',
        ]]);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $rows = IntelligenceOpportunity::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('rank_score')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function pulse(Request $request): JsonResponse
    {
        $symbols = $request->query('symbols');
        $list = is_string($symbols) ? explode(',', $symbols) : ['EURUSD', 'GBPUSD', 'USDJPY'];

        return response()->json(['data' => $this->engine->marketPulse($request->user(), $list)]);
    }

    public function heatmap(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->engine->heatmap($request->user())]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $provider = $this->providers->calendar($request->query('provider'));
        $result = $provider->fetch($request->query('currency'));

        return response()->json(['data' => array_merge($result, [
            'label' => 'CALENDAR_ADVISORY',
            'provider' => $provider->name(),
        ])]);
    }

    public function news(Request $request): JsonResponse
    {
        $provider = $this->providers->news($request->query('provider'));
        $result = $provider->fetch($request->query('symbol'));

        return response()->json(['data' => array_merge($result, [
            'label' => 'NEWS_ADVISORY',
            'provider' => $provider->name(),
        ])]);
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:64'],
            'technical' => ['nullable', 'array'],
            'ensemble' => ['nullable', 'array'],
            'market_quality' => ['nullable', 'array'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
        ]);
        $row = $this->ai->analyze($request->user(), $data, null, $request);

        return response()->json(['data' => $row], 201);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,system'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);
        $result = $this->ai->chat($request->user(), $data['messages'], $request);

        return response()->json(['data' => [
            'analysis' => $result['analysis'],
            'message' => $result['message'],
            'read_only' => true,
            'mutation_tools_available' => false,
            'disclaimer' => 'Read-only advisory chat — cannot mutate risk/settings/strategies or execute.',
        ]]);
    }

    public function calibration(Request $request): JsonResponse
    {
        $label = strtoupper((string) $request->query('evidence_label', EvidenceLabels::DEMO));
        if (! in_array($label, EvidenceLabels::ALL, true)) {
            return response()->json(['message' => 'Invalid evidence_label'], 422);
        }
        $samples = IntelligenceCalibrationSample::query()
            ->where('user_id', $request->user()->id)
            ->where('evidence_label', $label)
            ->latest('id')
            ->limit(200)
            ->get(['predicted_confidence', 'outcome_positive'])
            ->map(fn ($s) => [
                'predicted_confidence' => (float) $s->predicted_confidence,
                'outcome_positive' => $s->outcome_positive,
            ])->all();

        return response()->json(['data' => $this->calibration->calibrate($samples, $label)]);
    }

    public function addCalibrationSample(Request $request): JsonResponse
    {
        $data = $request->validate([
            'evidence_label' => ['required', 'string', 'in:HISTORICAL,DEMO,BACKTEST,OOS,EXECUTION,PORTFOLIO'],
            'predicted_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'outcome_positive' => ['nullable', 'boolean'],
        ]);
        $row = IntelligenceCalibrationSample::query()->create([
            'user_id' => $request->user()->id,
            'evidence_label' => $data['evidence_label'],
            'predicted_confidence' => $data['predicted_confidence'],
            'outcome_positive' => $data['outcome_positive'] ?? null,
            'sample_size_context' => 1,
            'guard_status' => 'OK',
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function usage(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'meters' => $this->usage->listFor($request->user()),
            'queue' => $this->queue->stats(),
        ]]);
    }

    public function research(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'label' => 'INTELLIGENCE_RESEARCH_ADVISORY',
            'assessments' => IntelligenceAssessment::query()->where('user_id', $request->user()->id)->latest('assessed_at')->limit(20)->get(),
            'evidence_labels' => EvidenceLabels::ALL,
            'disclaimer' => 'Research view keeps Historical/DEMO/Backtest/OOS/Execution/Portfolio evidence strictly distinct.',
            'safety' => $this->engine->health(),
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $row = IntelligenceSetting::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'scope' => 'USER'],
            [
                'ai_provider' => 'MOCK',
                'news_provider' => 'MOCK',
                'calendar_provider' => 'MOCK',
                'mode' => 'ADVISORY',
                'paid_providers_enabled' => false,
            ]
        );

        return response()->json(['data' => $row]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ai_provider' => ['nullable', 'string', 'in:MOCK'],
            'news_provider' => ['nullable', 'string', 'in:MOCK,UNAVAILABLE'],
            'calendar_provider' => ['nullable', 'string', 'in:MOCK,UNAVAILABLE'],
            'mode' => ['nullable', 'string', 'in:ADVISORY,SHADOW'],
            'ai_budget_monthly' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'cache_ttl_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
        ]);
        // Paid providers cannot be enabled via API in Phase 13
        $row = IntelligenceSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'scope' => 'USER'],
            array_merge($data, ['paid_providers_enabled' => false])
        );
        $this->audit->record('intelligence.settings_updated', $row, [], $data, $request);

        return response()->json(['data' => $row]);
    }

    public function queueStats(): JsonResponse
    {
        return response()->json(['data' => $this->queue->stats()]);
    }

    public function processQueue(Request $request): JsonResponse
    {
        $results = $this->queue->process(5);

        return response()->json(['data' => ['results' => $results]]);
    }

    public function refuseMutate(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', 'unknown');
        $result = $this->engine->refuseMutation($action);
        $this->audit->record('intelligence.mutation_refused', $request->user(), [], $result, $request);

        return response()->json(['data' => $result], 403);
    }

    /** @return list<array<string, mixed>> */
    private function synthCandles(int $n = 120): array
    {
        $out = [];
        $px = 1.1;
        for ($i = 0; $i < $n; $i++) {
            $drift = (($i % 17) - 8) * 0.00008;
            $open = $px;
            $close = $px + $drift;
            $t0 = strtotime('2025-01-01T00:00:00Z') + $i * 300;
            $out[] = [
                'open' => $open,
                'high' => max($open, $close) + 0.00025,
                'low' => min($open, $close) - 0.00025,
                'close' => $close,
                'open_time' => date('c', $t0),
                'close_time' => date('c', $t0 + 299),
            ];
            $px = $close;
        }

        return $out;
    }
}
