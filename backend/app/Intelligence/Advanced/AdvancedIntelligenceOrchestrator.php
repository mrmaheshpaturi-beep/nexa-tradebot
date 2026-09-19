<?php

namespace App\Intelligence\Advanced;

use App\Intelligence\AIAnalysisService;
use App\Intelligence\IntelligenceJobQueue;
use App\Intelligence\OpportunityRanker;
use App\Intelligence\Providers\ProviderResolver;
use App\Intelligence\Support\EvidenceLabels;
use App\Intelligence\Support\IntelligenceSafety;
use App\Intelligence\TradeIntelligenceEngineService;
use App\Intelligence\UsageMeter;
use App\Models\IntelligenceAdvancedSnapshot;
use App\Models\IntelligenceAssessment;
use App\Models\IntelligenceCalibrationSample;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Phase 17 Advanced Intelligence Orchestrator.
 * Coordinates deeper market features on top of Phase 13 TradeIntelligenceEngine.
 * Does NOT create a parallel conflicting intelligence stack.
 * Zero MT5/order_send/risk/config/approval/deployment mutation paths.
 */
class AdvancedIntelligenceOrchestrator
{
    public function __construct(
        private readonly TradeIntelligenceEngineService $phase13 = new TradeIntelligenceEngineService,
        private readonly MarketFeatureEngine $features = new MarketFeatureEngine,
        private readonly DeepMarketStructureEngine $structure = new DeepMarketStructureEngine,
        private readonly ApprovedStrategyEnsemble $approvedEnsemble = new ApprovedStrategyEnsemble,
        private readonly CrossMarketContextEngine $crossMarket = new CrossMarketContextEngine,
        private readonly HistoricalAnalogEngine $analogs = new HistoricalAnalogEngine,
        private readonly ContextPackEngine $contextPack = new ContextPackEngine,
        private readonly DeterministicScoringSeparator $separator = new DeterministicScoringSeparator,
        private readonly UncertaintyEvidenceEngine $uncertainty = new UncertaintyEvidenceEngine,
        private readonly SuitabilityAnalysisEngine $suitability = new SuitabilityAnalysisEngine,
        private readonly ResearchMemoryService $memory = new ResearchMemoryService,
        private readonly OpportunityRanker $ranker = new OpportunityRanker,
        private readonly ProviderResolver $providers = new ProviderResolver,
        private readonly AIAnalysisService $ai = new AIAnalysisService,
        private readonly UsageMeter $usage = new UsageMeter,
        private readonly IntelligenceJobQueue $queue = new IntelligenceJobQueue,
        private readonly AuditService $audit = new AuditService,
        private readonly PromptBuilder $prompts = new PromptBuilder,
    ) {}

    public function health(): array
    {
        $p13 = $this->phase13->health();

        return array_merge(IntelligenceSafety::safetyFlags(), [
            'phase' => 17,
            'status' => 'READY',
            'orchestrator' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'extends' => $p13['engine'] ?? IntelligenceSafety::ENGINE_VERSION,
            'phase13' => $p13,
            'modes' => ['ADVISORY', 'SHADOW'],
            'duplicate_stack' => false,
            'ci_providers' => 'MOCK_ONLY',
            'advisory_only' => true,
            'shadow_supported' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function assess(User $user, array $input, ?Request $request = null): IntelligenceAdvancedSnapshot
    {
        $mode = strtoupper((string) ($input['mode'] ?? 'ADVISORY'));
        if (! in_array($mode, ['ADVISORY', 'SHADOW'], true)) {
            $mode = 'ADVISORY';
        }

        $symbol = strtoupper((string) ($input['symbol'] ?? 'EURUSD'));
        $timeframe = (string) ($input['timeframe'] ?? 'M5');
        $candles = is_array($input['candles'] ?? null) ? $input['candles'] : [];
        $htf = is_array($input['htf_candles'] ?? null) ? $input['htf_candles'] : null;
        $mtfMap = is_array($input['mtf_candles'] ?? null) ? $input['mtf_candles'] : [];

        $cacheKey = 'adv:assess:'.hash('sha256', json_encode([
            'u' => $user->id,
            's' => $symbol,
            'tf' => $timeframe,
            'm' => $mode,
            'c' => $candles,
            'h' => $htf,
            'x' => $input['cross_market'] ?? [],
            'e' => $input['evaluations'] ?? [],
        ], JSON_THROW_ON_ERROR));
        $cachedId = $this->queue->cacheGet($cacheKey);
        if (is_string($cachedId)) {
            $hit = IntelligenceAdvancedSnapshot::query()
                ->where('public_id', $cachedId)
                ->where('user_id', $user->id)
                ->first();
            if ($hit) {
                return $hit;
            }
        }

        if (! $this->usage->consume($user, 'assessments', 1)) {
            return $this->persistUnavailable($user, $symbol, $timeframe, $mode, 'BUDGET_EXCEEDED', $request);
        }

        // Phase 13 base assessment (reuse engine — include_ai deferred to Phase 17 separator)
        $baseInput = $input;
        $baseInput['mode'] = $mode;
        $baseInput['include_ai'] = false;
        $assessment = $this->phase13->assess($user, $baseInput, $request);

        $featureAsOf = isset($input['feature_as_of_epoch']) ? (int) $input['feature_as_of_epoch'] : time();
        $featureObserved = isset($input['feature_observed_epoch']) ? (int) $input['feature_observed_epoch'] : $featureAsOf;
        $features = $this->features->extract($candles, $featureAsOf, $featureObserved);
        $deep = $this->structure->analyze($candles, $htf, $mtfMap);

        $evaluations = $input['evaluations'] ?? null;
        if (! is_array($evaluations) || $evaluations === []) {
            $evaluations = $this->proxyEvaluations($deep);
        }
        $ensemble = $this->approvedEnsemble->build($user, $evaluations);

        $crossMap = is_array($input['cross_market'] ?? null) ? $input['cross_market'] : [];
        if ($crossMap === [] && $candles !== []) {
            $crossMap = [$symbol => $candles];
        } else {
            $crossMap[$symbol] = $candles;
        }
        $cross = $this->crossMarket->build($symbol, $crossMap);
        $analogs = $this->analogs->find($candles);

        $this->usage->consume($user, 'calendar_calls', 1);
        $this->usage->consume($user, 'news_calls', 1);
        $calendar = $this->providers->calendar($input['calendar_provider'] ?? null)->fetch($this->currencyHint($symbol));
        $news = $this->providers->news($input['news_provider'] ?? null)->fetch($symbol);

        $ctx = $this->contextPack->pack([
            'calendar' => $calendar,
            'news' => $news,
            'portfolio' => $input['portfolio'] ?? null,
            'execution' => $input['execution'] ?? null,
            'session' => $input['session'] ?? null,
        ]);

        $evidenceIn = $input['evidence_buckets'] ?? [
            EvidenceLabels::DEMO => [],
            EvidenceLabels::BACKTEST => [],
            EvidenceLabels::HISTORICAL => [],
            EvidenceLabels::OOS => [],
            EvidenceLabels::EXECUTION => [],
            EvidenceLabels::PORTFOLIO => [],
        ];
        $evidence = EvidenceLabels::separate(is_array($evidenceIn) ? $evidenceIn : []);

        $candidate = [
            'symbol' => $symbol,
            'direction' => $ensemble['direction'],
            'ensemble' => $ensemble,
            'market_quality' => $assessment->market_quality ?? ['status' => 'UNKNOWN'],
            'mtf' => $deep['mtf'] ?? [],
            'spread' => $assessment->spread ?? ['points' => 1.2, 'cap_points' => 5],
            'anomaly' => $assessment->anomaly ?? ['detected' => false],
            'regime' => $deep['regime'] ?? [],
            'technical' => $deep['technical'] ?? [],
        ];
        $ranked = $this->ranker->rank([$candidate])[0];

        // Analog / feature soft adjustments stay deterministic (not AI)
        $analogBoost = 0.0;
        if (($analogs['status'] ?? '') === 'OK' && ($analogs['mean_forward_ret_5'] ?? null) !== null) {
            $dir = $ensemble['direction'] ?? 'NEUTRAL';
            $fwd = (float) $analogs['mean_forward_ret_5'];
            if (($dir === 'BUY' && $fwd > 0) || ($dir === 'SELL' && $fwd < 0)) {
                $analogBoost = 4.0;
            } elseif (($dir === 'BUY' && $fwd < 0) || ($dir === 'SELL' && $fwd > 0)) {
                $analogBoost = -4.0;
            }
        }
        if (! ($features['fresh'] ?? false)) {
            $analogBoost -= 6.0;
        }
        $ranked['rank_score'] = max(0, min(100, (float) $ranked['rank_score'] + $analogBoost));
        $ranked['ranking_breakdown']['analog_feature_adjust'] = $analogBoost;

        $samples = IntelligenceCalibrationSample::query()
            ->where('user_id', $user->id)
            ->where('evidence_label', EvidenceLabels::DEMO)
            ->latest('id')
            ->limit(100)
            ->get(['predicted_confidence', 'outcome_positive'])
            ->map(fn ($s) => [
                'predicted_confidence' => (float) $s->predicted_confidence,
                'outcome_positive' => $s->outcome_positive,
            ])->all();

        $uncertainty = $this->uncertainty->evaluate($samples, EvidenceLabels::DEMO, [
            'features_fresh' => (bool) ($features['fresh'] ?? false),
            'analogs_status' => $analogs['status'] ?? 'UNKNOWN',
            'evidence_ok' => (bool) ($evidence['ok'] ?? false),
            'market_quality_status' => $assessment->market_quality['status'] ?? 'UNKNOWN',
            'ensemble_conflicts' => $ensemble['conflicts'] ?? [],
        ]);

        $suit = $this->suitability->analyze([
            'market_quality_status' => $assessment->market_quality['status'] ?? 'UNKNOWN',
            'event_risk' => $ctx['event']['event_risk'] ?? 'LOW',
            'uncertainty_score' => $uncertainty['uncertainty']['score'] ?? 0.5,
            'mtf_agreement' => $deep['mtf_matrix']['agreement'] ?? 'PARTIAL',
            'governance_filter_status' => $ensemble['governance_filter']['status'] ?? 'OK',
            'mode' => $mode,
        ]);

        $aiRow = null;
        $aiPayload = null;
        if (($input['include_ai'] ?? true) === true) {
            $promptInput = $this->prompts->buildAnalysisInput([
                'symbol' => $symbol,
                'mode' => $mode,
                'technical' => $deep['technical'] ?? [],
                'ensemble' => $ensemble,
                'market_quality' => $assessment->market_quality,
                'features' => $features,
                'analogs' => ['status' => $analogs['status'], 'mean_forward_ret_5' => $analogs['mean_forward_ret_5'] ?? null],
                'suitability' => $suit,
                'mtf_matrix' => $deep['mtf_matrix'] ?? [],
            ]);
            $aiRow = $this->ai->analyze($user, $promptInput, $assessment->id, $request);
            $aiPayload = $aiRow->structured_output;
        }

        $separated = $this->separator->separate([
            'rank_score' => $ranked['rank_score'],
            'opportunity' => $ranked,
            'ensemble' => $ensemble,
            'features' => $features,
            'analogs' => $analogs,
            'sources' => ['ensemble', 'market_quality', 'mtf', 'features', 'analogs', 'suitability'],
        ], is_array($aiPayload) ? $aiPayload : null);

        $payload = [
            'phase' => 17,
            'orchestrator_version' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'extends_engine' => IntelligenceSafety::ENGINE_VERSION,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'mode' => $mode,
            'phase13_assessment_public_id' => $assessment->public_id,
            'features' => $features,
            'deep_structure' => [
                'structure' => $deep['structure'] ?? [],
                'support_resistance' => $deep['support_resistance'] ?? [],
                'trend' => $deep['trend'] ?? [],
                'momentum' => $deep['momentum'] ?? [],
                'volatility_ext' => $deep['volatility_ext'] ?? [],
            ],
            'technical' => $deep['technical'] ?? [],
            'mtf' => $deep['mtf'] ?? [],
            'mtf_matrix' => $deep['mtf_matrix'] ?? [],
            'regime' => $deep['regime'] ?? [],
            'ensemble' => $ensemble,
            'cross_market' => $cross,
            'analogs' => $analogs,
            'context' => $ctx,
            'opportunity' => $ranked,
            'evidence_buckets' => $evidence['buckets'],
            'evidence_ok' => $evidence['ok'],
            'evidence_error' => $evidence['error'],
            'uncertainty' => $uncertainty,
            'suitability' => $suit,
            'scoring_separation' => $separated,
            'safety' => IntelligenceSafety::safetyFlags(),
            'disclaimer' => 'ADVISORY / SHADOW advanced intelligence — never executes. LIVE HARD_BLOCKED. Phase 14 qualification + Phase 9 risk + Phase 10 execution + Phase 16 governance remain mandatory.',
        ];

        $contentHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $snapshot = IntelligenceAdvancedSnapshot::query()->create([
            'user_id' => $user->id,
            'intelligence_assessment_id' => $assessment->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'mode' => $mode,
            'status' => $evidence['ok'] ? (($features['status'] ?? '') === 'STALE' ? 'STALE_FEATURES' : 'READY') : 'EVIDENCE_REFUSED',
            'orchestrator_version' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'feature_schema_version' => MarketFeatureEngine::SCHEMA_VERSION,
            'content_hash' => $contentHash,
            'features' => $features,
            'deep_structure' => $payload['deep_structure'],
            'mtf_matrix' => $deep['mtf_matrix'] ?? [],
            'ensemble' => $ensemble,
            'cross_market' => $cross,
            'analogs' => $analogs,
            'context_pack' => $ctx,
            'uncertainty' => $uncertainty,
            'suitability' => $suit,
            'scoring_separation' => $separated,
            'payload' => $payload,
            'confidence' => $uncertainty['calibration']['calibrated_confidence']
                ?? min(0.9, max(0.1, ((float) $ranked['rank_score']) / 100)),
            'live_execution' => false,
            'order_send' => false,
            'assessed_at' => now(),
        ]);

        // Immutable pre-trade research memory
        $this->memory->record($user, 'PRE_TRADE', [
            'mode' => $mode,
            'symbol' => $symbol,
            'snapshot_public_id' => $snapshot->public_id,
            'content_hash' => $contentHash,
            'suitability' => $suit['label'] ?? null,
            'rank_score' => $ranked['rank_score'],
        ], $symbol, $assessment->id, $request);

        if (($input['record_research'] ?? false) === true) {
            $this->memory->record($user, 'RESEARCH', [
                'mode' => $mode,
                'symbol' => $symbol,
                'analogs' => $analogs,
                'features' => $features,
            ], $symbol, $assessment->id, $request);
        }

        $this->heartbeat();
        $this->audit->record('intelligence.advanced_assessed', $snapshot, [], [
            'symbol' => $symbol,
            'mode' => $mode,
            'content_hash' => $contentHash,
            'orchestrator' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'order_send' => false,
            'live_execution' => false,
            'extends_phase_13' => true,
        ], $request);

        $this->queue->cachePut($cacheKey, $snapshot->public_id, 120);

        return $snapshot->load(['assessment', 'assessment.aiAnalyses']);
    }

    public function desk(User $user): array
    {
        $snaps = IntelligenceAdvancedSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('assessed_at')
            ->limit(10)
            ->get();

        return [
            'label' => 'ADVANCED_INTELLIGENCE_DESK_ADVISORY',
            'disclaimer' => 'ADVISORY / SHADOW only — Phase 17 extends Phase 13.',
            'health' => $this->health(),
            'recent_snapshots' => $snaps,
            'phase13_pulse' => $this->phase13->marketPulse($user),
            'safety' => IntelligenceSafety::safetyFlags(),
        ];
    }

    /**
     * Refuse mutation / promotion / execution from advanced intelligence.
     *
     * @return array<string, mixed>
     */
    public function refuseMutation(string $action): array
    {
        return [
            'refused' => true,
            'action' => $action,
            'reason' => 'ADVANCED_INTELLIGENCE_HAS_NO_MUTATION_PATH',
            'order_send' => false,
            'live_execution' => IntelligenceSafety::HARD_BLOCKED_LIVE,
            'live_auto_exists' => false,
            'phase_16_governance_mandatory' => true,
            'phase_14_qualification_mandatory' => true,
            'phase_9_risk_mandatory' => true,
            'phase_10_sole_execution' => true,
            'allowed' => false,
        ];
    }

    public function phase13Engine(): TradeIntelligenceEngineService
    {
        return $this->phase13;
    }

    public function memoryService(): ResearchMemoryService
    {
        return $this->memory;
    }

    private function heartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'ADVANCED_INTELLIGENCE',
            'instance_id' => gethostname() ?: 'local',
            'status' => 'ONLINE',
            'environment' => 'SIMULATION',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => array_merge(['phase' => 17], IntelligenceSafety::safetyFlags()),
            'metadata' => ['extends' => 'TRADE_INTELLIGENCE'],
        ]);
    }

    private function persistUnavailable(
        User $user,
        string $symbol,
        string $tf,
        string $mode,
        string $reason,
        ?Request $request,
    ): IntelligenceAdvancedSnapshot {
        $payload = [
            'status' => 'UNAVAILABLE',
            'reason' => $reason,
            'safety' => IntelligenceSafety::safetyFlags(),
            'disclaimer' => 'Advanced intelligence unavailable — fail closed.',
        ];
        $snap = IntelligenceAdvancedSnapshot::query()->create([
            'user_id' => $user->id,
            'symbol' => $symbol,
            'timeframe' => $tf,
            'mode' => $mode,
            'status' => 'UNAVAILABLE',
            'orchestrator_version' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'feature_schema_version' => MarketFeatureEngine::SCHEMA_VERSION,
            'content_hash' => hash('sha256', $reason),
            'payload' => $payload,
            'live_execution' => false,
            'order_send' => false,
            'assessed_at' => now(),
        ]);
        $this->audit->record('intelligence.advanced_unavailable', $snap, [], ['reason' => $reason], $request);

        return $snap;
    }

    /** @param  array<string, mixed>  $deep @return list<array<string, mixed>> */
    private function proxyEvaluations(array $deep): array
    {
        $bias = $deep['technical']['bias'] ?? 'NEUTRAL';
        if ($bias === 'NEUTRAL') {
            return [];
        }
        $dir = $bias === 'BULLISH' ? 'BUY' : 'SELL';

        return [[
            'plugin_key' => 'intel_proxy_trend',
            'direction' => $dir,
            'raw_score' => 62,
            'evidence' => [
                ['family' => 'TREND', 'weight' => 1.0, 'detail' => 'Deep trend proxy'],
                ['family' => 'MOMENTUM', 'weight' => 0.8, 'detail' => 'Momentum proxy'],
            ],
        ], [
            'plugin_key' => 'intel_proxy_mtf',
            'direction' => ($deep['mtf']['htf_bias'] ?? '') === 'BULLISH' ? 'BUY'
                : (($deep['mtf']['htf_bias'] ?? '') === 'BEARISH' ? 'SELL' : $dir),
            'raw_score' => 54,
            'evidence' => [
                ['family' => 'MTF', 'weight' => 1.0, 'detail' => 'MTF matrix proxy'],
            ],
        ]];
    }

    private function currencyHint(string $symbol): string
    {
        if (str_contains($symbol, 'USD')) {
            return 'USD';
        }
        if (str_contains($symbol, 'EUR')) {
            return 'EUR';
        }

        return 'USD';
    }
}
