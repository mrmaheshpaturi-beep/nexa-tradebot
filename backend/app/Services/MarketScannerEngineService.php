<?php

namespace App\Services;

use App\Models\ScannerConfig;
use App\Models\ScannerRun;
use App\Models\ServiceHeartbeat;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Strategies\StrategyRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Market Scanner Engine — coordinated multi-symbol / multi-timeframe / multi-strategy scans.
 * ANALYSIS AND ORCHESTRATION ONLY. Does not send broker orders.
 */
class MarketScannerEngineService
{
    public const DEFAULT_SYMBOLS = ['EURUSD', 'XAUUSD', 'GBPUSD', 'USDJPY'];

    public const DEFAULT_TIMEFRAMES = ['M5', 'M15', 'H1'];

    public function __construct(
        private readonly MarketDataEngineService $market,
        private readonly TechnicalAnalysisEngine $technical,
        private readonly StrategyRegistry $registry,
        private readonly StrategyEngineService $strategies,
        private readonly SignalOrchestratorService $orchestrator,
        private readonly AlertPipelineService $alerts,
        private readonly ConfluenceEngineService $confluence,
    ) {}

    /** @return array<string, mixed> */
    public function defaultUniverse(): array
    {
        return [
            'symbols' => self::DEFAULT_SYMBOLS,
            'timeframes' => self::DEFAULT_TIMEFRAMES,
            'trigger_modes' => ['ON_CANDLE_CLOSE', 'ON_INTERVAL', 'MANUAL'],
        ];
    }

    public function getOrCreateConfig(User $user, ?int $configId = null): ScannerConfig
    {
        if ($configId) {
            $config = ScannerConfig::query()->where('user_id', $user->id)->whereKey($configId)->firstOrFail();

            return $config;
        }

        $existing = ScannerConfig::query()->where('user_id', $user->id)->where('enabled', true)->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return ScannerConfig::query()->create([
            'user_id' => $user->id,
            'name' => 'Default Universe',
            'symbols' => self::DEFAULT_SYMBOLS,
            'timeframes' => self::DEFAULT_TIMEFRAMES,
            'plugin_keys' => null,
            'strategy_ids' => null,
            'trigger_mode' => 'MANUAL',
            'interval_seconds' => 300,
            'enabled' => true,
            'prefer' => 'simulation',
            'create_signals' => true,
            'create_candidates' => true,
            'version' => 1,
            'metadata' => ['phase' => 8],
        ]);
    }

    /**
     * Run a coordinated scan. Idempotent for ON_CANDLE_CLOSE via run_key.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runScan(User $user, array $options = []): array
    {
        $trigger = strtoupper((string) ($options['trigger'] ?? 'MANUAL'));
        if (! in_array($trigger, ['ON_CANDLE_CLOSE', 'ON_INTERVAL', 'MANUAL'], true)) {
            $trigger = 'MANUAL';
        }

        $config = $this->getOrCreateConfig($user, isset($options['config_id']) ? (int) $options['config_id'] : null);
        $symbols = array_values(array_unique(array_map('strtoupper', $options['symbols'] ?? $config->symbols ?? self::DEFAULT_SYMBOLS)));
        $timeframes = array_values(array_unique(array_map('strtoupper', $options['timeframes'] ?? $config->timeframes ?? self::DEFAULT_TIMEFRAMES)));
        $prefer = (string) ($options['prefer'] ?? $config->prefer ?? 'simulation');
        $createSignals = (bool) ($options['create_signals'] ?? $config->create_signals);
        $createCandidates = (bool) ($options['create_candidates'] ?? $config->create_candidates);

        // Pre-compute candle close keys for idempotency (sample first symbol/TF pair set)
        $closeKeys = [];
        foreach ($symbols as $symbol) {
            foreach ($timeframes as $tf) {
                try {
                    $tech = $this->technical->snapshot($symbol, $tf, 40, $prefer);
                    $closeKeys[] = $symbol.'|'.$tf.'|'.$tech->candleCloseKey;
                } catch (\Throwable) {
                    $closeKeys[] = $symbol.'|'.$tf.'|unavailable';
                }
            }
        }

        $runKey = $this->buildRunKey($user, $config, $trigger, $prefer, $closeKeys);

        if (in_array($trigger, ['ON_CANDLE_CLOSE', 'ON_INTERVAL'], true)) {
            $existing = ScannerRun::query()->where('user_id', $user->id)->where('run_key', $runKey)->first();
            if ($existing && $existing->status === 'COMPLETED') {
                return [
                    'phase' => 8,
                    'ok' => true,
                    'idempotent' => true,
                    'replayed' => true,
                    'run' => $existing,
                    'matrix' => $existing->summary['matrix'] ?? [],
                    'orchestrator' => ['created' => 0, 'replayed' => 0],
                    'execution' => $this->executionFlags(),
                    'disclaimer' => 'Idempotent scan replay — no duplicate work for this candle/interval key.',
                ];
            }
        }

        $lockKey = 'scanner:run:'.$user->id.':'.$runKey;
        if (! Cache::add($lockKey, 1, 60)) {
            return [
                'phase' => 8,
                'ok' => false,
                'reason' => 'SCAN_IN_PROGRESS',
                'execution' => $this->executionFlags(),
            ];
        }

        $run = ScannerRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'run_key' => $runKey],
            [
                'scanner_config_id' => $config->id,
                'trigger' => $trigger,
                'status' => 'RUNNING',
                'prefer' => $prefer,
                'symbols_scanned' => 0,
                'timeframes_scanned' => 0,
                'strategies_evaluated' => 0,
                'candidates_created' => 0,
                'signals_created' => 0,
                'errors_count' => 0,
                'errors' => [],
                'summary' => null,
                'started_at' => Carbon::now('UTC'),
                'finished_at' => null,
                'metadata' => [
                    'phase' => 8,
                    'symbols' => $symbols,
                    'timeframes' => $timeframes,
                    'order_send' => false,
                ],
            ],
        );

        $errors = [];
        $matrix = [];
        $ingestRows = [];
        $evaluated = 0;
        $signalsCreated = 0;
        $symbolsDone = [];
        $tfsDone = [];

        try {
            $strategySet = $this->resolveStrategies($user, $config, $options);

            foreach ($symbols as $symbol) {
                $symbolsDone[$symbol] = true;
                foreach ($timeframes as $tf) {
                    $tfsDone[$tf] = true;
                    try {
                        $cell = $this->scanCell($user, $symbol, $tf, $prefer, $strategySet, $createSignals);
                        $evaluated += $cell['evaluated'];
                        $signalsCreated += $cell['signals_created'];
                        $matrix[] = $cell['matrix_row'];
                        foreach ($cell['ingest_rows'] as $row) {
                            $ingestRows[] = $row;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'symbol' => $symbol,
                            'timeframe' => $tf,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            $orch = ['created' => 0, 'replayed' => 0, 'candidates' => []];
            if ($createCandidates && $ingestRows !== []) {
                $orch = $this->orchestrator->ingest($user, $run, $ingestRows);
            }

            $run->status = $errors === [] ? 'COMPLETED' : 'COMPLETED';
            $run->symbols_scanned = count($symbolsDone);
            $run->timeframes_scanned = count($tfsDone);
            $run->strategies_evaluated = $evaluated;
            $run->candidates_created = (int) ($orch['created'] ?? 0);
            $run->signals_created = $signalsCreated;
            $run->errors_count = count($errors);
            $run->errors = $errors;
            $run->summary = [
                'matrix' => $matrix,
                'orchestrator' => [
                    'created' => $orch['created'] ?? 0,
                    'replayed' => $orch['replayed'] ?? 0,
                ],
                'execution' => $this->executionFlags(),
            ];
            $run->finished_at = Carbon::now('UTC');
            $run->save();

            $this->touchHeartbeat(count($errors) === 0, [
                'run_id' => $run->id,
                'symbols' => $run->symbols_scanned,
                'candidates' => $run->candidates_created,
                'errors' => $run->errors_count,
            ]);

            $this->alerts->emit(
                $user,
                'SCAN_COMPLETED',
                'Market scan completed',
                sprintf('%d symbols · %d evaluations · %d new candidates', $run->symbols_scanned, $evaluated, $run->candidates_created),
                $errors === [] ? 'INFO' : 'WARN',
                'IN_APP',
                null,
                $run,
                ['errors_count' => count($errors)],
            );

            return [
                'phase' => 8,
                'ok' => true,
                'idempotent' => false,
                'replayed' => false,
                'run' => $run->fresh(),
                'matrix' => $matrix,
                'orchestrator' => [
                    'created' => $orch['created'] ?? 0,
                    'replayed' => $orch['replayed'] ?? 0,
                ],
                'execution' => $this->executionFlags(),
                'disclaimer' => 'Scanner identifies opportunities only. Candidates are not broker orders. Scores are not win probabilities.',
            ];
        } catch (\Throwable $e) {
            $run->status = 'FAILED';
            $run->errors = [['error' => $e->getMessage()]];
            $run->errors_count = 1;
            $run->finished_at = Carbon::now('UTC');
            $run->save();
            $this->touchHeartbeat(false, ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Live board: latest run matrix + active candidate queue + health.
     *
     * @return array<string, mixed>
     */
    public function board(User $user, array $filters = []): array
    {
        $config = $this->getOrCreateConfig($user);
        $lastRun = ScannerRun::query()->where('user_id', $user->id)->latest('id')->first();
        $queue = $this->orchestrator->queue($user, $filters);

        return [
            'phase' => 8,
            'config' => $config,
            'last_run' => $lastRun,
            'matrix' => $lastRun?->summary['matrix'] ?? [],
            'queue' => $queue,
            'health' => $this->health($user),
            'universe' => [
                'symbols' => $config->symbols,
                'timeframes' => $config->timeframes,
                'trigger_mode' => $config->trigger_mode,
            ],
            'execution' => $this->executionFlags(),
            'disclaimer' => 'Live scanner board — analysis and candidate presentation only.',
        ];
    }

    /** @return array<string, mixed> */
    public function matrix(User $user, ?array $symbols = null, ?array $timeframes = null, string $prefer = 'simulation'): array
    {
        $result = $this->runScan($user, [
            'trigger' => 'MANUAL',
            'symbols' => $symbols,
            'timeframes' => $timeframes,
            'prefer' => $prefer,
            'create_signals' => false,
            'create_candidates' => true,
        ]);

        return [
            'phase' => 8,
            'matrix' => $result['matrix'] ?? [],
            'run_id' => $result['run']->id ?? null,
            'execution' => $this->executionFlags(),
        ];
    }

    /** @return array<string, mixed> */
    public function health(?User $user = null): array
    {
        $hb = ServiceHeartbeat::query()->where('service', 'MARKET_SCANNER')->latest('observed_at')->first();
        $lastRun = $user
            ? ScannerRun::query()->where('user_id', $user->id)->latest('id')->first()
            : ScannerRun::query()->latest('id')->first();

        return [
            'service' => 'MARKET_SCANNER',
            'phase' => 8,
            'status' => $hb ? ($hb->status === 'ONLINE' ? 'OK' : 'DEGRADED') : 'NO_HEARTBEAT_YET',
            'heartbeat' => $hb,
            'last_scan' => $lastRun ? [
                'id' => $lastRun->id,
                'status' => $lastRun->status,
                'trigger' => $lastRun->trigger,
                'finished_at' => $lastRun->finished_at,
                'symbols_scanned' => $lastRun->symbols_scanned,
                'candidates_created' => $lastRun->candidates_created,
                'errors_count' => $lastRun->errors_count,
            ] : null,
            'orchestrator' => $user ? $this->orchestrator->health($user) : null,
            'alerts' => $this->alerts->health(),
            'execution' => $this->executionFlags(),
            'auto_trading' => 'DISABLED',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<TradingStrategy|null>
     */
    private function resolveStrategies(User $user, ScannerConfig $config, array $options): array
    {
        if (! empty($options['strategy_ids']) || ! empty($config->strategy_ids)) {
            $ids = $options['strategy_ids'] ?? $config->strategy_ids;

            return TradingStrategy::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->where('enabled', true)
                ->where('status', 'ACTIVE')
                ->get()
                ->all();
        }

        $enabled = TradingStrategy::query()
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->where('status', 'ACTIVE')
            ->get();

        if ($enabled->isNotEmpty()) {
            return $enabled->all();
        }

        // Catalog-mode scan shell (no persisted strategy required)
        return [null];
    }

    /**
     * @param  list<TradingStrategy|null>  $strategies
     * @return array<string, mixed>
     */
    private function scanCell(
        User $user,
        string $symbol,
        string $timeframe,
        string $prefer,
        array $strategies,
        bool $createSignals,
    ): array {
        $marketSnap = $this->market->snapshot([$symbol], $symbol, $timeframe, 40, $prefer, persist: false);
        $tech = $this->technical->snapshot($symbol, $timeframe, 120, $prefer);
        $mtf = $this->technical->multiTimeframe($symbol, $timeframe, ['M15', 'H1'], 120, $prefer);
        $quality = $marketSnap['data_quality'] ?? null;
        $freshness = [
            'candle_close_key' => $tech->candleCloseKey,
            'technical_status' => $tech->status,
            'stale' => ($tech->status ?? '') === 'REFUSED',
        ];

        $evals = [];
        $ingestRows = [];
        $signalsCreated = 0;
        $evaluated = 0;

        $onlyNull = count($strategies) === 1 && $strategies[0] === null;
        if ($onlyNull) {
            $scan = $this->strategies->scan($user, $symbol, $timeframe, $prefer);
            $evals = $scan['evaluations'] ?? [];
            $evaluated = count($evals);
            $confluence = $scan['confluence'] ?? [];
            foreach ($evals as $ev) {
                if (($ev['status'] ?? '') !== 'SIGNAL') {
                    continue;
                }
                $ingestRows[] = $this->rowFromEval($ev, $symbol, $timeframe, $tech->candleCloseKey, $confluence, $quality, $freshness, null, null);
            }
        } else {
            foreach ($strategies as $strategy) {
                if (! $strategy instanceof TradingStrategy) {
                    continue;
                }
                // Respect strategy symbol/TF assignment when set
                $symOk = empty($strategy->symbols) || in_array($symbol, array_map('strtoupper', $strategy->symbols), true);
                $tfOk = empty($strategy->timeframes) || in_array($timeframe, array_map('strtoupper', $strategy->timeframes), true);
                if (! $symOk || ! $tfOk) {
                    continue;
                }
                $result = $this->strategies->evaluateStrategy($user, $strategy, $symbol, $timeframe, $prefer, $createSignals);
                $evaluated++;
                $ev = $result['evaluation'] ?? [];
                $evals[] = $ev;
                if (($result['signal_result']['created'] ?? false) === true) {
                    $signalsCreated++;
                }
                if (($ev['status'] ?? '') === 'SIGNAL') {
                    $signalModel = $result['signal'] ?? null;
                    $signalId = is_object($signalModel) ? ($signalModel->id ?? null) : ($signalModel['id'] ?? null);
                    $ingestRows[] = $this->rowFromEval(
                        $ev,
                        $symbol,
                        $timeframe,
                        $tech->candleCloseKey,
                        $result['confluence'] ?? [],
                        $quality,
                        $freshness,
                        $strategy->id,
                        $signalId,
                        (bool) ($strategy->auto_simulation ?? false),
                        (int) $strategy->version,
                    );
                }
            }
            $actionable = array_values(array_filter($evals, fn ($e) => ($e['status'] ?? '') === 'SIGNAL'));
            $confluence = $this->confluence->merge($actionable);
        }

        $actionableCount = count(array_filter($evals, fn ($e) => ($e['status'] ?? '') === 'SIGNAL'));
        $confluenceScore = (float) (($confluence['score'] ?? 0));
        $direction = $confluence['direction'] ?? 'NEUTRAL';

        return [
            'evaluated' => $evaluated,
            'signals_created' => $signalsCreated,
            'ingest_rows' => $ingestRows,
            'matrix_row' => [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'direction' => $direction,
                'confluence_score' => $confluenceScore,
                'signal_plugins' => $confluence['supporting_plugins'] ?? [],
                'actionable_count' => $actionableCount,
                'technical_status' => $tech->status,
                'data_quality' => is_array($quality) ? ($quality['score'] ?? $quality['status'] ?? null) : $quality,
                'freshness' => $freshness,
                'candle_close_key' => $tech->candleCloseKey,
                'market_source' => $marketSnap['source'] ?? null,
            ],
        ];
    }

    /** @param  array<string, mixed>  $ev */
    private function rowFromEval(
        array $ev,
        string $symbol,
        string $timeframe,
        string $candleCloseKey,
        array $confluence,
        mixed $quality,
        array $freshness,
        ?int $strategyId,
        mixed $signalId,
        bool $markSimulate = false,
        int $configVersion = 1,
    ): array {
        return [
            'status' => $ev['status'] ?? 'SIGNAL',
            'direction' => $ev['direction'] ?? 'NEUTRAL',
            'plugin_key' => $ev['plugin_key'] ?? null,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candle_close_key' => $candleCloseKey,
            'raw_score' => $ev['raw_score'] ?? 0,
            'confluence_score' => $confluence['score'] ?? ($ev['raw_score'] ?? 0),
            'confluence' => $confluence,
            'score_breakdown' => $ev['score_breakdown'] ?? null,
            'evidence' => $ev['evidence'] ?? null,
            'quality' => is_array($quality) ? $quality : ['score' => $quality],
            'freshness' => $freshness,
            'trading_strategy_id' => $strategyId,
            'signal_id' => $signalId,
            'marked_for_simulate' => $markSimulate,
            'configuration_version' => $configVersion,
            'expiry_minutes' => 120,
        ];
    }

    /** @param  list<string>  $closeKeys */
    private function buildRunKey(User $user, ScannerConfig $config, string $trigger, string $prefer, array $closeKeys): string
    {
        if ($trigger === 'MANUAL') {
            // Manual scans always unique but still collision-safe
            return hash('sha256', implode('|', [
                'manual',
                $user->id,
                $config->id,
                $prefer,
                Carbon::now('UTC')->format('YmdHisu'),
                implode(',', $closeKeys),
            ]));
        }

        if ($trigger === 'ON_INTERVAL') {
            $bucket = (int) floor(time() / max(60, (int) ($config->interval_seconds ?: 300)));

            return hash('sha256', implode('|', [
                'interval',
                $user->id,
                $config->id,
                $config->version,
                $prefer,
                $bucket,
            ]));
        }

        // ON_CANDLE_CLOSE — stable for same candle closes
        return hash('sha256', implode('|', [
            'candle',
            $user->id,
            $config->id,
            $config->version,
            $prefer,
            implode(',', $closeKeys),
        ]));
    }

    /** @return array<string, bool|string> */
    private function executionFlags(): array
    {
        return [
            'order_send' => false,
            'demo_execution' => false,
            'live_execution' => false,
            'broker_auto_trading' => false,
            'broker_routing' => false,
            'mode' => 'SCANNING_AND_CANDIDATES_ONLY',
        ];
    }

    /** @param  array<string, mixed>  $details */
    private function touchHeartbeat(bool $ok, array $details = []): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'MARKET_SCANNER',
            'instance_id' => gethostname() ?: 'local',
            'status' => $ok ? 'ONLINE' : 'DEGRADED',
            'environment' => 'SIMULATION',
            'observed_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
            'details' => array_merge(['phase' => 8, 'analysis_only' => true], $details),
            'metadata' => [
                'order_send' => false,
                'auto_trading' => false,
                'broker_routing' => false,
            ],
        ]);
    }
}
