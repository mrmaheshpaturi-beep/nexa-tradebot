<?php

namespace App\Intelligence;

use App\Intelligence\Providers\ProviderResolver;
use App\Intelligence\Support\EvidenceLabels;
use App\Intelligence\Support\IntelligenceSafety;
use App\Models\IntelligenceAssessment;
use App\Models\IntelligenceCalibrationSample;
use App\Models\IntelligenceOpportunity;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Phase 13 TradeIntelligenceEngine — deterministic/versioned advisory intelligence.
 * Shadow/advisory modes only. Zero broker/risk/settings/strategy mutation paths.
 */
class TradeIntelligenceEngineService
{
    public function __construct(
        private readonly TechnicalMtfRegimeIntelligence $technical = new TechnicalMtfRegimeIntelligence,
        private readonly MarketQualityEngines $quality = new MarketQualityEngines,
        private readonly StrategyEnsemble $ensemble = new StrategyEnsemble,
        private readonly OpportunityRanker $ranker = new OpportunityRanker,
        private readonly IntelligenceRules $rules = new IntelligenceRules,
        private readonly ConfidenceCalibration $calibration = new ConfidenceCalibration,
        private readonly ProviderResolver $providers = new ProviderResolver,
        private readonly AIAnalysisService $ai = new AIAnalysisService,
        private readonly UsageMeter $usage = new UsageMeter,
        private readonly IntelligenceJobQueue $queue = new IntelligenceJobQueue,
        private readonly AuditService $audit = new AuditService,
    ) {}

    public function health(): array
    {
        $hb = ServiceHeartbeat::query()->where('service', 'TRADE_INTELLIGENCE')->latest('observed_at')->first();

        return array_merge(IntelligenceSafety::safetyFlags(), [
            'phase' => 13,
            'status' => 'READY',
            'engine' => IntelligenceSafety::ENGINE_VERSION,
            'modes' => ['ADVISORY', 'SHADOW'],
            'ai_provider' => $this->ai->providerName(),
            'news_provider' => $this->providers->news()->name(),
            'calendar_provider' => $this->providers->calendar()->name(),
            'queue' => $this->queue->stats(),
            'last_heartbeat_at' => $hb?->observed_at,
            'advisory_only' => true,
            'shadow_supported' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     *   symbol, timeframe?, candles[], htf_candles?, evaluations[], spread_points?,
     *   mode?, evidence_buckets?, include_ai?, calendar_provider?, news_provider?
     */
    public function assess(User $user, array $input, ?Request $request = null): IntelligenceAssessment
    {
        $mode = strtoupper((string) ($input['mode'] ?? 'ADVISORY'));
        if (! in_array($mode, ['ADVISORY', 'SHADOW'], true)) {
            $mode = 'ADVISORY';
        }

        $symbol = strtoupper((string) ($input['symbol'] ?? 'EURUSD'));
        $timeframe = (string) ($input['timeframe'] ?? 'M5');
        $candles = $input['candles'] ?? [];
        $htf = $input['htf_candles'] ?? null;
        if (! is_array($candles)) {
            $candles = [];
        }

        $cacheKey = 'assess:'.hash('sha256', json_encode([
            'u' => $user->id,
            's' => $symbol,
            'tf' => $timeframe,
            'm' => $mode,
            'c' => $candles,
            'h' => $htf,
            'e' => $input['evaluations'] ?? [],
        ], JSON_THROW_ON_ERROR));
        $cachedId = $this->queue->cacheGet($cacheKey);
        if (is_string($cachedId)) {
            $hit = IntelligenceAssessment::query()->where('public_id', $cachedId)->where('user_id', $user->id)->first();
            if ($hit) {
                return $hit;
            }
        }

        if (! $this->usage->consume($user, 'assessments', 1)) {
            // Still produce a safe unavailable assessment rather than throwing
            return $this->persistUnavailable($user, $symbol, $timeframe, $mode, 'BUDGET_EXCEEDED', $request);
        }

        $tmr = $this->technical->analyze($candles, is_array($htf) ? $htf : null);
        $mq = $this->quality->evaluate($candles, [
            'spread_points' => (float) ($input['spread_points'] ?? 1.2),
            'spread_cap' => (float) ($input['spread_cap'] ?? 5),
        ]);

        $evaluations = $input['evaluations'] ?? $this->defaultEvaluations($tmr);
        $ens = $this->ensemble->build(is_array($evaluations) ? $evaluations : []);

        $this->usage->consume($user, 'calendar_calls', 1);
        $this->usage->consume($user, 'news_calls', 1);
        $calendar = $this->providers->calendar($input['calendar_provider'] ?? null)->fetch($this->currencyHint($symbol));
        $news = $this->providers->news($input['news_provider'] ?? null)->fetch($symbol);

        $evidenceIn = $input['evidence_buckets'] ?? [
            EvidenceLabels::DEMO => [],
            EvidenceLabels::BACKTEST => [],
            EvidenceLabels::HISTORICAL => [],
            EvidenceLabels::OOS => [],
            EvidenceLabels::EXECUTION => [],
            EvidenceLabels::PORTFOLIO => [],
        ];
        $evidence = EvidenceLabels::separate(is_array($evidenceIn) ? $evidenceIn : []);

        $context = [
            'mode' => $mode,
            'market_quality' => $mq['market_quality'],
            'spread' => $mq['spread'],
            'anomaly' => $mq['anomaly'],
            'ensemble' => $ens,
            'calendar' => $calendar,
            'news' => $news,
        ];
        $rulesFired = $this->rules->evaluate($context);

        $candidate = [
            'symbol' => $symbol,
            'direction' => $ens['direction'],
            'ensemble' => $ens,
            'market_quality' => $mq['market_quality'],
            'mtf' => $tmr['mtf'],
            'spread' => $mq['spread'],
            'anomaly' => $mq['anomaly'],
            'regime' => $tmr['regime'],
            'technical' => $tmr['technical'],
        ];
        $ranked = $this->ranker->rank([$candidate])[0];

        $confidenceRaw = min(0.9, max(0.1, ((float) $ranked['rank_score']) / 100));
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
        $cal = $this->calibration->calibrate($samples, EvidenceLabels::DEMO);

        $inputHash = hash('sha256', json_encode([
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'mode' => $mode,
            'candles_n' => count($candles),
            'evaluations' => $evaluations,
        ], JSON_THROW_ON_ERROR));

        $payload = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'mode' => $mode,
            'technical' => $tmr['technical'],
            'mtf' => $tmr['mtf'],
            'regime' => $tmr['regime'],
            'ensemble' => $ens,
            'market_quality' => $mq['market_quality'],
            'volatility' => $mq['volatility'],
            'spread' => $mq['spread'],
            'anomaly' => $mq['anomaly'],
            'calendar' => $calendar,
            'news' => $news,
            'opportunity' => $ranked,
            'evidence_buckets' => $evidence['buckets'],
            'evidence_ok' => $evidence['ok'],
            'evidence_error' => $evidence['error'],
            'rules_fired' => $rulesFired,
            'calibration' => $cal,
            'confidence' => $cal['calibrated_confidence'] ?? $confidenceRaw,
            'confidence_status' => $cal['status'],
            'safety' => IntelligenceSafety::safetyFlags(),
            'disclaimer' => 'ADVISORY / SHADOW intelligence only — never executes trades. LIVE HARD_BLOCKED.',
        ];

        $contentHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $assessment = IntelligenceAssessment::query()->create([
            'user_id' => $user->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'mode' => $mode,
            'status' => $evidence['ok'] ? 'READY' : 'EVIDENCE_REFUSED',
            'engine_version' => IntelligenceSafety::ENGINE_VERSION,
            'content_hash' => $contentHash,
            'input_hash' => $inputHash,
            'technical' => $tmr['technical'],
            'mtf' => $tmr['mtf'],
            'regime' => $tmr['regime'],
            'ensemble' => $ens,
            'market_quality' => $mq['market_quality'],
            'volatility' => $mq['volatility'],
            'spread' => $mq['spread'],
            'anomaly' => $mq['anomaly'],
            'calendar' => $calendar,
            'news' => $news,
            'opportunity' => $ranked,
            'evidence_buckets' => $evidence['buckets'],
            'rules_fired' => $rulesFired,
            'payload' => $payload,
            'confidence' => $payload['confidence'],
            'confidence_status' => $payload['confidence_status'],
            'live_execution' => false,
            'order_send' => false,
            'assessed_at' => now(),
        ]);

        IntelligenceOpportunity::query()->create([
            'user_id' => $user->id,
            'intelligence_assessment_id' => $assessment->id,
            'symbol' => $symbol,
            'direction' => $ranked['direction'] ?? null,
            'mode' => $mode,
            'rank_score' => $ranked['rank_score'],
            'rank_position' => $ranked['rank_position'],
            'ranking_breakdown' => $ranked['ranking_breakdown'],
            'conflicts' => $ens['conflicts'] ?? [],
            'evidence_families' => $ens['families'] ?? [],
            'payload' => $ranked,
            'disclaimer' => 'ADVISORY / SHADOW — does not execute.',
        ]);

        if (($input['include_ai'] ?? true) === true) {
            $this->ai->analyze($user, [
                'symbol' => $symbol,
                'technical' => $tmr['technical'],
                'ensemble' => $ens,
                'market_quality' => $mq['market_quality'],
                'mode' => $mode,
            ], $assessment->id, $request);
        }

        $this->heartbeat();
        $this->audit->record('intelligence.assessed', $assessment, [], [
            'symbol' => $symbol,
            'mode' => $mode,
            'content_hash' => $contentHash,
            'order_send' => false,
            'live_execution' => false,
        ], $request);

        $this->queue->cachePut($cacheKey, $assessment->public_id, 120);

        return $assessment;
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return list<IntelligenceOpportunity>
     */
    public function opportunityBoard(User $user, array $inputs, string $mode = 'ADVISORY', ?Request $request = null): array
    {
        $candidates = [];
        foreach ($inputs as $input) {
            $input['mode'] = $mode;
            $input['include_ai'] = false;
            $a = $this->assess($user, $input, $request);
            $candidates[] = array_merge($a->opportunity ?? [], [
                'assessment_public_id' => $a->public_id,
                'symbol' => $a->symbol,
            ]);
        }
        $ranked = $this->ranker->rank($candidates);
        $out = [];
        foreach ($ranked as $row) {
            $out[] = IntelligenceOpportunity::query()->create([
                'user_id' => $user->id,
                'symbol' => (string) ($row['symbol'] ?? 'UNKNOWN'),
                'direction' => $row['direction'] ?? null,
                'mode' => $mode,
                'rank_score' => $row['rank_score'],
                'rank_position' => $row['rank_position'],
                'ranking_breakdown' => $row['ranking_breakdown'],
                'conflicts' => $row['ensemble']['conflicts'] ?? [],
                'evidence_families' => $row['ensemble']['families'] ?? [],
                'payload' => $row,
                'disclaimer' => 'ADVISORY / SHADOW opportunity board — never implies live execution.',
            ]);
        }

        return $out;
    }

    public function marketPulse(User $user, array $symbols = ['EURUSD', 'GBPUSD', 'USDJPY']): array
    {
        $pulse = [];
        foreach ($symbols as $sym) {
            $latest = IntelligenceAssessment::query()
                ->where('user_id', $user->id)
                ->where('symbol', strtoupper((string) $sym))
                ->latest('assessed_at')
                ->first();
            $pulse[] = [
                'symbol' => strtoupper((string) $sym),
                'mode' => $latest?->mode ?? 'ADVISORY',
                'technical_bias' => $latest?->technical['bias'] ?? null,
                'regime' => $latest?->regime['label'] ?? null,
                'quality' => $latest?->market_quality['status'] ?? 'UNKNOWN',
                'rank_score' => $latest?->opportunity['rank_score'] ?? null,
                'assessment_public_id' => $latest?->public_id,
                'label' => 'ADVISORY',
            ];
        }

        return [
            'label' => 'MARKET_PULSE_ADVISORY',
            'disclaimer' => 'Pulse is advisory/shadow only.',
            'items' => $pulse,
            'safety' => IntelligenceSafety::safetyFlags(),
        ];
    }

    public function heatmap(User $user): array
    {
        $rows = IntelligenceOpportunity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('rank_score')
            ->limit(50)
            ->get(['symbol', 'direction', 'rank_score', 'mode']);

        return [
            'label' => 'OPPORTUNITY_HEATMAP_ADVISORY',
            'cells' => $rows->map(fn ($r) => [
                'symbol' => $r->symbol,
                'direction' => $r->direction,
                'score' => (float) $r->rank_score,
                'mode' => $r->mode,
            ])->all(),
            'disclaimer' => 'Heatmap scores are advisory — not execution signals.',
        ];
    }

    /**
     * Refuse any mutate/promote/execute request from intelligence layer.
     */
    public function refuseMutation(string $action): array
    {
        return [
            'refused' => true,
            'action' => $action,
            'reason' => 'INTELLIGENCE_HAS_NO_MUTATION_PATH',
            'order_send' => false,
            'live_execution' => IntelligenceSafety::HARD_BLOCKED_LIVE,
            'allowed' => false,
        ];
    }

    private function heartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'TRADE_INTELLIGENCE',
            'instance_id' => gethostname() ?: 'local',
            'status' => 'ONLINE',
            'environment' => 'SIMULATION',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => array_merge(['phase' => 13], IntelligenceSafety::safetyFlags()),
            'metadata' => [],
        ]);
    }

    private function persistUnavailable(User $user, string $symbol, string $tf, string $mode, string $reason, ?Request $request): IntelligenceAssessment
    {
        $payload = [
            'status' => 'UNAVAILABLE',
            'reason' => $reason,
            'safety' => IntelligenceSafety::safetyFlags(),
            'disclaimer' => 'Intelligence unavailable — fail closed.',
        ];
        $a = IntelligenceAssessment::query()->create([
            'user_id' => $user->id,
            'symbol' => $symbol,
            'timeframe' => $tf,
            'mode' => $mode,
            'status' => 'UNAVAILABLE',
            'engine_version' => IntelligenceSafety::ENGINE_VERSION,
            'content_hash' => hash('sha256', $reason),
            'input_hash' => hash('sha256', $symbol.$reason),
            'payload' => $payload,
            'live_execution' => false,
            'order_send' => false,
            'assessed_at' => now(),
        ]);
        $this->audit->record('intelligence.unavailable', $a, [], ['reason' => $reason], $request);

        return $a;
    }

    /** @return list<array<string, mixed>> */
    private function defaultEvaluations(array $tmr): array
    {
        $bias = $tmr['technical']['bias'] ?? 'NEUTRAL';
        if ($bias === 'NEUTRAL') {
            return [];
        }
        $dir = $bias === 'BULLISH' ? 'BUY' : 'SELL';

        return [[
            'plugin_key' => 'intel_proxy_trend',
            'direction' => $dir,
            'raw_score' => 60,
            'evidence' => [
                ['family' => 'TREND', 'weight' => 1.0, 'detail' => 'EMA/RSI proxy'],
                ['family' => 'MOMENTUM', 'weight' => 0.8, 'detail' => 'RSI context'],
            ],
        ], [
            'plugin_key' => 'intel_proxy_mtf',
            'direction' => ($tmr['mtf']['htf_bias'] ?? '') === 'BULLISH' ? 'BUY' : (($tmr['mtf']['htf_bias'] ?? '') === 'BEARISH' ? 'SELL' : $dir),
            'raw_score' => 50,
            'evidence' => [
                ['family' => 'MTF', 'weight' => 1.0, 'detail' => 'HTF alignment proxy'],
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
