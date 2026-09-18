<?php

namespace App\Services;

use App\Models\ServiceHeartbeat;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;
use App\Strategies\StrategyRegistry;
use Illuminate\Support\Carbon;

/**
 * Strategy Engine — evaluates plugins against market + technical snapshots.
 * ANALYSIS AND SIGNALS ONLY. No broker execution.
 */
class StrategyEngineService
{
    public function __construct(
        private readonly MarketDataEngineService $market,
        private readonly TechnicalAnalysisEngine $technical,
        private readonly StrategyRegistry $registry,
        private readonly StrategyGateService $gates,
        private readonly ConfluenceEngineService $confluence,
        private readonly SignalEngineService $signals,
    ) {}

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return $this->registry->catalog();
    }

    /**
     * Evaluate a single strategy instance for symbol/timeframe.
     *
     * @return array<string, mixed>
     */
    public function evaluateStrategy(
        User $user,
        TradingStrategy $strategy,
        ?string $symbol = null,
        ?string $timeframe = null,
        string $prefer = 'simulation',
        bool $createSignal = true,
    ): array {
        abort_unless($strategy->user_id === $user->id, 404);

        $symbol = strtoupper($symbol ?? ($strategy->symbols[0] ?? 'EURUSD'));
        $timeframe = strtoupper($timeframe ?? ($strategy->timeframes[0] ?? 'M5'));
        $pluginKey = $strategy->plugin_key ?: $this->inferPluginKey($strategy);
        if (! $this->registry->has($pluginKey)) {
            return [
                'ok' => false,
                'reason' => 'UNKNOWN_PLUGIN',
                'plugin_key' => $pluginKey,
                'execution' => $this->executionFlags(),
            ];
        }

        $marketSnap = $this->market->snapshot([$symbol], $symbol, $timeframe, 40, $prefer, persist: false);
        $tech = $this->technical->snapshot($symbol, $timeframe, 120, $prefer);
        $higher = $strategy->higher_timeframes ?? ['M15', 'H1'];
        if (! is_array($higher)) {
            $higher = ['M15', 'H1'];
        }
        $mtf = $this->technical->multiTimeframe($symbol, $timeframe, $higher, 120, $prefer);
        $gate = $this->gates->evaluate($strategy, $symbol, $timeframe, $marketSnap, $tech);

        $plugin = $this->registry->get($pluginKey);
        $params = array_merge($plugin->defaultParameters(), $strategy->parameters ?? []);
        $context = new StrategyContext(
            strategy: $strategy,
            pluginKey: $pluginKey,
            symbol: $symbol,
            timeframe: $timeframe,
            marketSnapshot: $marketSnap,
            technical: $tech,
            mtf: $mtf,
            parameters: $params,
            allowedSessions: $strategy->sessions ?? [],
            prefer: $prefer,
            candleCloseKey: $tech->candleCloseKey,
            configurationVersion: (int) $strategy->version,
        );

        $primary = $gate['allowed']
            ? $plugin->evaluate($context)
            : StrategyEvaluation::blocked($pluginKey, $gate['reason'] ?? 'GATE_BLOCKED');

        // Peer plugins for confluence (same symbol/timeframe, built-in catalog category peers)
        $peerEvals = [$primary->toArray()];
        foreach ($this->registry->all() as $peer) {
            if ($peer->key() === $pluginKey) {
                continue;
            }
            // Light confluence: only same category peers to limit cost
            if ($peer->category() !== $plugin->category() && $peer->evidenceFamily() !== 'MTF') {
                continue;
            }
            $peerEvals[] = $peer->evaluate($context)->toArray();
        }
        $actionable = array_values(array_filter($peerEvals, fn ($e) => ($e['status'] ?? '') === 'SIGNAL'));
        $confluence = $this->confluence->merge($actionable, $primary->direction ?? '');

        $signalResult = ['signal' => null, 'skipped' => 'CREATE_DISABLED'];
        if ($createSignal) {
            $signalResult = $this->signals->maybeCreate(
                $user,
                $strategy,
                $symbol,
                $timeframe,
                $tech->candleCloseKey,
                $primary,
                $confluence,
                $peerEvals,
                $gate,
                (int) $strategy->version,
            );
        }

        $this->touchHeartbeat($primary->status === 'SIGNAL');

        return [
            'ok' => true,
            'phase' => 7,
            'strategy_id' => $strategy->id,
            'plugin_key' => $pluginKey,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candle_close_key' => $tech->candleCloseKey,
            'gate' => $gate,
            'evaluation' => $primary->toArray(),
            'peer_evaluations' => $peerEvals,
            'confluence' => $confluence,
            'technical' => $tech->toArray(),
            'mtf' => $mtf->toArray(),
            'market' => [
                'source' => $marketSnap['source'] ?? null,
                'environment' => $marketSnap['environment'] ?? null,
                'data_quality' => $marketSnap['data_quality'] ?? null,
            ],
            'signal' => $signalResult['signal']?->load(['strategy', 'instrument']),
            'signal_result' => [
                'skipped' => $signalResult['skipped'] ?? null,
                'created' => $signalResult['created'] ?? false,
                'replayed' => $signalResult['replayed'] ?? false,
            ],
            'auto_simulation' => (bool) ($strategy->auto_simulation ?? false),
            'auto_trading_enabled' => false,
            'execution' => $this->executionFlags(),
            'disclaimer' => 'Scores are transparent confluence measures (0–100), not win probabilities. No broker execution.',
        ];
    }

    /**
     * Evaluate all enabled strategies for a user (scheduler entrypoint).
     *
     * @return array<string, mixed>
     */
    public function evaluateAll(User $user, string $prefer = 'simulation'): array
    {
        $this->signals->expireDue();
        $strategies = $user->strategies()->where('enabled', true)->where('status', 'ACTIVE')->get();
        $results = [];
        foreach ($strategies as $strategy) {
            foreach ($strategy->symbols ?? [] as $symbol) {
                foreach ($strategy->timeframes ?? [] as $timeframe) {
                    $results[] = $this->evaluateStrategy($user, $strategy, $symbol, $timeframe, $prefer, true);
                }
            }
        }

        return [
            'phase' => 7,
            'count' => count($results),
            'results' => $results,
            'execution' => $this->executionFlags(),
            'evaluated_at' => Carbon::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * Scanner: run all catalog plugins against a symbol (no persistence strategy required).
     *
     * @return array<string, mixed>
     */
    public function scan(User $user, string $symbol, string $timeframe = 'M5', string $prefer = 'simulation'): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $marketSnap = $this->market->snapshot([$symbol], $symbol, $timeframe, 40, $prefer, persist: false);
        $tech = $this->technical->snapshot($symbol, $timeframe, 120, $prefer);
        $mtf = $this->technical->multiTimeframe($symbol, $timeframe, ['M15', 'H1'], 120, $prefer);

        // Temporary strategy shell for context (not persisted)
        $shell = new TradingStrategy([
            'user_id' => $user->id,
            'name' => 'Scanner',
            'slug' => 'scanner',
            'category' => 'CUSTOM',
            'symbols' => [$symbol],
            'timeframes' => [$timeframe],
            'parameters' => [],
            'enabled' => true,
            'status' => 'ACTIVE',
            'version' => 1,
            'auto_trading_enabled' => false,
            'auto_simulation' => false,
        ]);

        $evals = [];
        foreach ($this->registry->all() as $plugin) {
            $context = new StrategyContext(
                strategy: $shell,
                pluginKey: $plugin->key(),
                symbol: $symbol,
                timeframe: $timeframe,
                marketSnapshot: $marketSnap,
                technical: $tech,
                mtf: $mtf,
                parameters: $plugin->defaultParameters(),
                allowedSessions: [],
                prefer: $prefer,
                candleCloseKey: $tech->candleCloseKey,
                configurationVersion: 1,
            );
            $evals[] = $plugin->evaluate($context)->toArray();
        }
        $actionable = array_values(array_filter($evals, fn ($e) => ($e['status'] ?? '') === 'SIGNAL'));
        $confluence = $this->confluence->merge($actionable);

        return [
            'phase' => 7,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candle_close_key' => $tech->candleCloseKey,
            'evaluations' => $evals,
            'confluence' => $confluence,
            'technical_status' => $tech->status,
            'execution' => $this->executionFlags(),
            'disclaimer' => 'Scanner is analysis-only. Scores are not win probabilities.',
        ];
    }

    /** @return array<string, mixed> */
    public function matrix(User $user, string $prefer = 'simulation'): array
    {
        $symbols = ['EURUSD', 'XAUUSD'];
        $timeframes = ['M5', 'M15'];
        $rows = [];
        foreach ($symbols as $symbol) {
            foreach ($timeframes as $tf) {
                $scan = $this->scan($user, $symbol, $tf, $prefer);
                $rows[] = [
                    'symbol' => $symbol,
                    'timeframe' => $tf,
                    'confluence_score' => $scan['confluence']['score'] ?? 0,
                    'direction' => $scan['confluence']['direction'] ?? 'NEUTRAL',
                    'signal_plugins' => $scan['confluence']['supporting_plugins'] ?? [],
                ];
            }
        }

        return ['phase' => 7, 'matrix' => $rows, 'execution' => $this->executionFlags()];
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $hb = ServiceHeartbeat::query()->where('service', 'STRATEGY_ENGINE')->latest('observed_at')->first();

        return [
            'service' => 'STRATEGY_ENGINE',
            'phase' => 7,
            'plugins' => count($this->registry->all()),
            'heartbeat' => $hb,
            'execution' => $this->executionFlags(),
            'auto_trading' => 'DISABLED',
            'status' => $hb ? 'OK' : 'NO_HEARTBEAT_YET',
        ];
    }

    private function inferPluginKey(TradingStrategy $strategy): string
    {
        $slug = strtolower((string) $strategy->slug);
        if ($this->registry->has($slug)) {
            return $slug;
        }
        $map = [
            'trend' => 'ema_trend',
            'momentum' => 'rsi_momentum',
            'reversal' => 'bollinger_mean_reversion',
            'breakout' => 'breakout',
        ];
        $cat = strtolower((string) $strategy->category);

        return $map[$cat] ?? 'ema_trend';
    }

    /** @return array<string, bool|string> */
    private function executionFlags(): array
    {
        return [
            'order_send' => false,
            'demo_execution' => false,
            'live_execution' => false,
            'broker_auto_trading' => false,
            'mode' => 'ANALYSIS_AND_SIGNALS_ONLY',
        ];
    }

    private function touchHeartbeat(bool $ok): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'STRATEGY_ENGINE',
            'instance_id' => gethostname() ?: 'local',
            'status' => $ok ? 'ONLINE' : 'DEGRADED',
            'environment' => 'SIMULATION',
            'observed_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
            'details' => [
                'phase' => 7,
                'analysis_only' => true,
            ],
            'metadata' => [
                'order_send' => false,
                'auto_trading' => false,
            ],
        ]);
    }
}
