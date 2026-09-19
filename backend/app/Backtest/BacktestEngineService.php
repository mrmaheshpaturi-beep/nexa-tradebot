<?php

namespace App\Backtest;

use App\Analytics\MetricsCalculator;
use App\Enums\TradingEnvironment;
use App\Models\BacktestDataSnapshot;
use App\Models\BacktestMonteCarloPath;
use App\Models\BacktestOptimizationTrial;
use App\Models\BacktestRun;
use App\Models\BacktestWalkForwardFold;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12 BacktestEngine — deterministic research simulation.
 * Environment label ALWAYS BACKTEST. Zero broker-changing calls. Never auto-promotes.
 */
class BacktestEngineService
{
    public const ENGINE_VERSION = 'BacktestEngine/v1';

    public function __construct(
        private readonly ClosedCandleTechnicalBuilder $technical,
        private readonly SimulatedRiskAdapter $risk,
        private readonly SimulatedManagementAdapter $management,
        private readonly BacktestJobQueue $queue,
        private readonly OverfittingControls $overfit,
        private readonly MetricsCalculator $metrics,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  list<array<string, mixed>>|null  $mtfCandles
     */
    public function createDataSnapshot(
        User $user,
        string $symbol,
        string $timeframe,
        array $candles,
        ?array $mtfCandles = null,
        string $source = 'INLINE',
    ): BacktestDataSnapshot {
        $normalized = array_values(array_map(fn ($c) => $this->normalizeCandle($c), $candles));
        $mtf = $mtfCandles === null ? null : array_values(array_map(fn ($c) => $this->normalizeCandle($c), $mtfCandles));
        $hash = hash('sha256', json_encode(['c' => $normalized, 'm' => $mtf], JSON_THROW_ON_ERROR));

        return BacktestDataSnapshot::query()->create([
            'user_id' => $user->id,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'source' => $source,
            'candle_count' => count($normalized),
            'content_hash' => $hash,
            'candles' => $normalized,
            'mtf_candles' => $mtf,
            'from_open_time' => $normalized[0]['open_time'] ?? null,
            'to_close_time' => $normalized[count($normalized) - 1]['close_time'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $costConfig
     * @param  array<string, mixed>  $riskProfile
     * @param  array<string, mixed>  $mgmtPolicy
     */
    public function queueRun(
        User $user,
        BacktestDataSnapshot $snapshot,
        string $strategyKey,
        array $parameters = [],
        array $costConfig = [],
        array $riskProfile = [],
        array $mgmtPolicy = [],
        string $intrabarPolicy = IntrabarPolicy::OHLC_PATH,
        string $runKind = 'SINGLE',
        ?int $seed = null,
        ?Request $request = null,
    ): BacktestRun {
        abort_unless($snapshot->user_id === $user->id, 404);

        $run = BacktestRun::query()->create([
            'user_id' => $user->id,
            'backtest_data_snapshot_id' => $snapshot->id,
            'environment' => TradingEnvironment::Backtest,
            'status' => 'QUEUED',
            'run_kind' => strtoupper($runKind),
            'strategy_key' => $strategyKey,
            'strategy_version' => $parameters['strategy_version'] ?? '1',
            'config_version' => (int) ($parameters['config_version'] ?? 1),
            'parameters' => array_merge($parameters, [
                'risk_profile' => $riskProfile,
                'management_policy' => $mgmtPolicy,
            ]),
            'cost_model' => (new CostModel($costConfig))->toArray(),
            'intrabar_policy' => $intrabarPolicy,
            'closed_candle_only' => true,
            'no_lookahead' => true,
            'mtf_protected' => true,
            'seed' => $seed,
            'queued_at' => now(),
        ]);

        $job = $this->queue->enqueue($user, 'BACKTEST_'.$run->run_kind, [
            'run_public_id' => $run->public_id,
        ], $run->id);

        $this->audit->record('backtest.queued', $run, [], [
            'job' => $job->public_id,
            'environment' => 'BACKTEST',
            'auto_promote' => false,
        ], $request);

        return $run->fresh();
    }

    public function processNextJobs(int $limit = 3): array
    {
        $jobs = $this->queue->drain($limit);
        $results = [];
        foreach ($jobs as $job) {
            try {
                $run = $job->run;
                if (! $run) {
                    $this->queue->complete($job, false, 'MISSING_RUN');
                    continue;
                }
                $this->executeRun($run);
                $this->queue->complete($job, true);
                $results[] = ['job' => $job->public_id, 'run' => $run->public_id, 'status' => 'COMPLETED'];
            } catch (\Throwable $e) {
                if (isset($run)) {
                    $run->forceFill([
                        'status' => 'FAILED',
                        'error_message' => substr($e->getMessage(), 0, 500),
                        'finished_at' => now(),
                    ])->save();
                }
                $this->queue->complete($job, false, substr($e->getMessage(), 0, 500));
                $results[] = ['job' => $job->public_id, 'error' => $e->getMessage()];
            }
        }
        $this->heartbeat();

        return $results;
    }

    public function executeRun(BacktestRun $run): BacktestRun
    {
        if ($run->environment !== TradingEnvironment::Backtest) {
            throw ValidationException::withMessages(['environment' => 'Must be BACKTEST']);
        }
        $run->forceFill(['status' => 'RUNNING', 'started_at' => now()])->save();
        $snapshot = $run->dataSnapshot;
        if (! $snapshot) {
            throw ValidationException::withMessages(['snapshot' => 'Missing data snapshot']);
        }

        $kind = $run->run_kind;
        $result = match ($kind) {
            'WALK_FORWARD' => $this->runWalkForward($run, $snapshot),
            'OPTIMIZATION' => $this->runOptimization($run, $snapshot),
            'MONTE_CARLO' => $this->runMonteCarlo($run, $snapshot),
            'PORTFOLIO' => $this->runPortfolio($run, $snapshot),
            default => $this->runSingle($run, $snapshot),
        };

        $lineage = [
            'engine' => self::ENGINE_VERSION,
            'environment' => 'BACKTEST',
            'strategy_key' => $run->strategy_key,
            'strategy_version' => $run->strategy_version,
            'config_version' => $run->config_version,
            'data_snapshot_public_id' => $snapshot->public_id,
            'data_content_hash' => $snapshot->content_hash,
            'parameters' => $run->parameters,
            'cost_model' => $run->cost_model,
            'intrabar_policy' => $run->intrabar_policy,
            'closed_candle_only' => true,
            'no_lookahead' => true,
            'mtf_protected' => true,
            'seed' => $run->seed,
            'timestamps' => [
                'queued_at' => optional($run->queued_at)?->toIso8601String(),
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ],
            'broker_changing_calls' => 0,
            'auto_promote' => false,
        ];
        $lineageHash = hash('sha256', json_encode($lineage, JSON_THROW_ON_ERROR));

        $run->forceFill([
            'status' => 'COMPLETED',
            'metrics' => $result['metrics'],
            'equity_curve' => $result['equity_curve'],
            'trades' => $result['trades'],
            'warnings' => $result['warnings'] ?? [],
            'lineage' => $lineage,
            'lineage_hash' => $lineageHash,
            'finished_at' => now(),
        ])->save();

        $this->heartbeat();

        return $run->fresh();
    }

    /**
     * Synchronous helper for tests / console.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{metrics:array,equity_curve:list<float>,trades:list<array>,warnings:list<string>}
     */
    public function simulate(
        array $candles,
        string $strategyKey = 'ema_trend',
        array $parameters = [],
        array $costConfig = [],
        array $riskProfile = [],
        array $mgmtPolicy = [],
        string $intrabarPolicy = IntrabarPolicy::OHLC_PATH,
        ?array $mtfCandles = null,
        float $startEquity = 10000.0,
    ): array {
        return $this->replay(
            array_values(array_map(fn ($c) => $this->normalizeCandle($c), $candles)),
            $strategyKey,
            $parameters,
            new CostModel($costConfig),
            $riskProfile,
            $mgmtPolicy,
            new IntrabarPolicy($intrabarPolicy),
            $mtfCandles === null ? null : array_values(array_map(fn ($c) => $this->normalizeCandle($c), $mtfCandles)),
            $startEquity,
        );
    }

    /** @return array{metrics:array,equity_curve:list<float>,trades:list<array>,warnings:list<string>} */
    private function runSingle(BacktestRun $run, BacktestDataSnapshot $snapshot): array
    {
        $params = $run->parameters ?? [];

        return $this->replay(
            $snapshot->candles ?? [],
            $run->strategy_key,
            $params,
            new CostModel($run->cost_model ?? []),
            $params['risk_profile'] ?? [],
            $params['management_policy'] ?? [],
            new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH),
            $snapshot->mtf_candles,
            (float) ($params['start_equity'] ?? 10000),
        );
    }

    private function runWalkForward(BacktestRun $run, BacktestDataSnapshot $snapshot): array
    {
        $candles = $snapshot->candles ?? [];
        $n = count($candles);
        $folds = max(2, (int) ($run->parameters['walk_forward_folds'] ?? 3));
        $isRatio = (float) ($run->parameters['is_ratio'] ?? 0.7);
        $foldSize = intdiv($n, $folds);
        $allTrades = [];
        $warnings = [];
        $oosMetricsList = [];

        for ($f = 0; $f < $folds; $f++) {
            $start = $f * $foldSize;
            $end = ($f === $folds - 1) ? $n : ($start + $foldSize);
            if ($end - $start < 10) {
                continue;
            }
            $slice = array_slice($candles, $start, $end - $start);
            $isCount = (int) floor(count($slice) * $isRatio);
            $isBars = array_slice($slice, 0, $isCount);
            $oosBars = array_slice($slice, $isCount);
            $isResult = $this->replay($isBars, $run->strategy_key, $run->parameters ?? [], new CostModel($run->cost_model ?? []), $run->parameters['risk_profile'] ?? [], $run->parameters['management_policy'] ?? [], new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH), null, (float) ($run->parameters['start_equity'] ?? 10000));
            $oosResult = $this->replay($oosBars, $run->strategy_key, $run->parameters ?? [], new CostModel($run->cost_model ?? []), $run->parameters['risk_profile'] ?? [], $run->parameters['management_policy'] ?? [], new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH), null, (float) ($run->parameters['start_equity'] ?? 10000));

            BacktestWalkForwardFold::query()->create([
                'backtest_run_id' => $run->id,
                'fold_index' => $f,
                'phase' => 'IS',
                'from_index' => $start,
                'to_index' => $start + $isCount - 1,
                'metrics' => $isResult['metrics'],
                'parameters_used' => $run->parameters,
            ]);
            BacktestWalkForwardFold::query()->create([
                'backtest_run_id' => $run->id,
                'fold_index' => $f,
                'phase' => 'OOS',
                'from_index' => $start + $isCount,
                'to_index' => $end - 1,
                'metrics' => $oosResult['metrics'],
                'parameters_used' => $run->parameters,
            ]);
            $allTrades = array_merge($allTrades, $oosResult['trades']);
            $oosMetricsList[] = $oosResult['metrics'];
            $check = $this->overfit->evaluate($isResult['metrics'], $oosResult['metrics'], 1);
            if ($check['overfit_flag']) {
                $warnings = array_merge($warnings, $check['notes']);
            }
        }

        $metrics = $this->metrics->compute($allTrades);
        $metrics['walk_forward'] = ['folds' => $folds, 'oos_fold_metrics' => $oosMetricsList];

        return [
            'metrics' => $metrics,
            'equity_curve' => $metrics['equity_curve'],
            'trades' => $allTrades,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function runOptimization(BacktestRun $run, BacktestDataSnapshot $snapshot): array
    {
        $ranges = $run->parameters['optimize'] ?? [
            'sl_atr_mult' => ['min' => 1.0, 'max' => 2.0, 'step' => 0.5],
            'tp_atr_mult' => ['min' => 2.0, 'max' => 3.0, 'step' => 0.5],
        ];
        $grid = $this->overfit->grid($ranges, OverfittingControls::MAX_TRIALS);
        $candles = $snapshot->candles ?? [];
        $split = (int) floor(count($candles) * 0.7);
        $isBars = array_slice($candles, 0, $split);
        $oosBars = array_slice($candles, $split);
        $best = null;
        $bestObj = -INF;
        $warnings = [];

        foreach ($grid as $i => $trialParams) {
            $riskProfile = array_merge($run->parameters['risk_profile'] ?? [], $trialParams);
            $isResult = $this->replay($isBars, $run->strategy_key, $run->parameters ?? [], new CostModel($run->cost_model ?? []), $riskProfile, $run->parameters['management_policy'] ?? [], new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH), null, (float) ($run->parameters['start_equity'] ?? 10000));
            $oosResult = $this->replay($oosBars, $run->strategy_key, $run->parameters ?? [], new CostModel($run->cost_model ?? []), $riskProfile, $run->parameters['management_policy'] ?? [], new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH), null, (float) ($run->parameters['start_equity'] ?? 10000));
            $obj = (float) ($isResult['metrics']['core']['expectancy'] ?? 0);
            $check = $this->overfit->evaluate($isResult['metrics'], $oosResult['metrics'], count($grid));
            BacktestOptimizationTrial::query()->create([
                'backtest_run_id' => $run->id,
                'trial_index' => $i,
                'parameters' => $trialParams,
                'metrics' => ['IS' => $isResult['metrics'], 'OOS' => $oosResult['metrics']],
                'objective' => $obj,
                'overfit_flag' => $check['overfit_flag'],
                'overfit_notes' => $check['notes'],
            ]);
            if (! $check['overfit_flag'] && $obj > $bestObj) {
                $bestObj = $obj;
                $best = ['params' => $trialParams, 'is' => $isResult, 'oos' => $oosResult];
            }
            if ($check['overfit_flag']) {
                $warnings = array_merge($warnings, $check['notes']);
            }
        }

        if ($best === null) {
            $warnings[] = 'NO_TRIAL_PASSED_OVERFIT_CONTROLS';
            $empty = $this->metrics->compute([]);

            return ['metrics' => $empty, 'equity_curve' => [], 'trades' => [], 'warnings' => array_values(array_unique($warnings))];
        }

        $metrics = $best['oos']['metrics'];
        $metrics['optimization'] = [
            'best_params' => $best['params'],
            'trials' => count($grid),
            'max_trials' => OverfittingControls::MAX_TRIALS,
            'selected_on' => 'IS_expectancy_with_OOS_overfit_gate',
            'auto_promote' => false,
            'note' => 'Winning params are NOT applied to live StrategySetting/RiskProfile',
        ];

        return [
            'metrics' => $metrics,
            'equity_curve' => $best['oos']['equity_curve'],
            'trades' => $best['oos']['trades'],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function runMonteCarlo(BacktestRun $run, BacktestDataSnapshot $snapshot): array
    {
        $base = $this->runSingle($run, $snapshot);
        $trades = $base['trades'];
        $paths = max(1, min(200, (int) ($run->parameters['monte_carlo_paths'] ?? 50)));
        $seed = (int) ($run->seed ?? 42);
        $pathMetrics = [];
        $finalEquities = [];

        for ($p = 0; $p < $paths; $p++) {
            $pathSeed = $seed + $p;
            $shuffled = $this->seededShuffle($trades, $pathSeed);
            $pnls = array_map(fn ($t) => (float) ($t['realized_pnl'] ?? 0), $shuffled);
            $eq = $this->metrics->equityCurve($pnls);
            $m = $this->metrics->compute($shuffled);
            BacktestMonteCarloPath::query()->create([
                'backtest_run_id' => $run->id,
                'path_index' => $p,
                'seed' => $pathSeed,
                'equity_curve' => $eq,
                'metrics' => $m,
            ]);
            $pathMetrics[] = $m;
            $finalEquities[] = $eq === [] ? 0.0 : $eq[count($eq) - 1];
        }
        sort($finalEquities);
        $metrics = $base['metrics'];
        $metrics['monte_carlo'] = [
            'paths' => $paths,
            'base_seed' => $seed,
            'seed_policy' => 'path_seed = base_seed + path_index',
            'reproducible' => true,
            'final_equity_p05' => $finalEquities[(int) floor(0.05 * (count($finalEquities) - 1))] ?? null,
            'final_equity_p50' => $finalEquities[(int) floor(0.50 * (count($finalEquities) - 1))] ?? null,
            'final_equity_p95' => $finalEquities[(int) floor(0.95 * (count($finalEquities) - 1))] ?? null,
        ];

        return [
            'metrics' => $metrics,
            'equity_curve' => $base['equity_curve'],
            'trades' => $trades,
            'warnings' => $base['warnings'] ?? [],
        ];
    }

    private function runPortfolio(BacktestRun $run, BacktestDataSnapshot $snapshot): array
    {
        // Portfolio: treat parameters.symbols as additional synthetic series offsets, or multi-strategy keys
        $strategies = $run->parameters['portfolio_strategies'] ?? [$run->strategy_key];
        $allTrades = [];
        $warnings = ['PORTFOLIO_USES_SHARED_SNAPSHOT_WITH_STRATEGY_OFFSETS'];
        foreach ($strategies as $i => $key) {
            $shifted = $snapshot->candles ?? [];
            // Deterministic slight parameter offset per sleeve to diversify without lookahead
            $risk = array_merge($run->parameters['risk_profile'] ?? [], ['sl_atr_mult' => 1.2 + 0.1 * $i]);
            $result = $this->replay(
                $shifted,
                (string) $key,
                $run->parameters ?? [],
                new CostModel($run->cost_model ?? []),
                $risk,
                $run->parameters['management_policy'] ?? [],
                new IntrabarPolicy($run->intrabar_policy ?: IntrabarPolicy::OHLC_PATH),
                $snapshot->mtf_candles,
                (float) ($run->parameters['start_equity'] ?? 10000),
            );
            foreach ($result['trades'] as $t) {
                $t['sleeve'] = $key;
                $allTrades[] = $t;
            }
        }
        usort($allTrades, fn ($a, $b) => ($a['exit_index'] ?? 0) <=> ($b['exit_index'] ?? 0));
        $metrics = $this->metrics->compute($allTrades);
        $metrics['portfolio'] = ['sleeves' => $strategies, 'trade_count' => count($allTrades)];

        return [
            'metrics' => $metrics,
            'equity_curve' => $metrics['equity_curve'],
            'trades' => $allTrades,
            'warnings' => $warnings,
        ];
    }

    /**
     * Core deterministic replay — closed candle only, no lookahead.
     *
     * @param  list<array<string, mixed>>  $candles
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $riskProfile
     * @param  array<string, mixed>  $mgmtPolicy
     * @param  list<array<string, mixed>>|null  $mtfCandles
     * @return array{metrics:array,equity_curve:list<float>,trades:list<array>,warnings:list<string>}
     */
    private function replay(
        array $candles,
        string $strategyKey,
        array $parameters,
        CostModel $costs,
        array $riskProfile,
        array $mgmtPolicy,
        IntrabarPolicy $intrabar,
        ?array $mtfCandles,
        float $startEquity,
    ): array {
        $warnings = [];
        $equity = $startEquity;
        $position = null;
        $trades = [];
        $warmup = max(60, (int) ($parameters['warmup'] ?? 60));
        $mgmtPolicy = $mgmtPolicy ?: [
            'break_even_enabled' => true,
            'break_even_trigger_r' => 1.0,
            'break_even_offset' => 0.0,
            'trailing_enabled' => true,
            'trailing_start_r' => 1.5,
        ];

        for ($i = 0; $i < count($candles); $i++) {
            $bar = $candles[$i];
            // Manage open position first on this closed bar (intrabar path)
            if ($position !== null) {
                $position = $this->management->manage($position, $bar, $mgmtPolicy, $intrabar);
                if (! empty($position['closed'])) {
                    $holdingDays = max(0, ($i - (int) $position['entry_index']) / 288); // rough M5 days
                    $exitFill = $costs->applyExit($position['direction'], (float) $position['exit_price'], (float) $position['volume'], $holdingDays);
                    $dirBuy = strtoupper($position['direction']) === 'BUY';
                    $raw = $dirBuy
                        ? ($exitFill['price'] - (float) $position['entry']) * (float) $position['volume'] * 100000
                        : ((float) $position['entry'] - $exitFill['price']) * (float) $position['volume'] * 100000;
                    $totalCosts = $exitFill['commission'] + $exitFill['swap'] + (float) ($position['entry_costs']['commission'] ?? 0)
                        + (float) ($position['entry_costs']['spread_cost'] ?? 0) + (float) ($position['entry_costs']['slippage'] ?? 0)
                        + $exitFill['spread_cost'] + $exitFill['slippage'];
                    $pnl = $raw - $totalCosts;
                    $equity += $pnl;
                    $initialRisk = abs((float) $position['entry'] - (float) $position['initial_sl']);
                    $rMult = $initialRisk > 1e-12
                        ? (($dirBuy ? ($exitFill['price'] - (float) $position['entry']) : ((float) $position['entry'] - $exitFill['price'])) / $initialRisk)
                        : null;
                    $trades[] = [
                        'entry_index' => $position['entry_index'],
                        'exit_index' => $i,
                        'direction' => $position['direction'],
                        'entry' => $position['entry'],
                        'exit' => $exitFill['price'],
                        'volume' => $position['volume'],
                        'realized_pnl' => round($pnl, 4),
                        'r_multiple' => $rMult !== null ? round($rMult, 8) : null,
                        'mae' => $position['mae'] ?? null,
                        'mfe' => $position['mfe'] ?? null,
                        'spread_cost' => ($position['entry_costs']['spread_cost'] ?? 0) + $exitFill['spread_cost'],
                        'commission' => ($position['entry_costs']['commission'] ?? 0) + $exitFill['commission'],
                        'slippage' => ($position['entry_costs']['slippage'] ?? 0) + $exitFill['slippage'],
                        'swap' => $exitFill['swap'],
                        'break_even_applied' => ! empty($position['break_even_applied']),
                        'trailing_used' => ! empty($position['trailing_used']),
                        'partials_count' => (int) ($position['partials_count'] ?? 0),
                        'exit_reason' => $position['exit_hit'] ?? 'UNKNOWN',
                        'strategy_key' => $strategyKey,
                    ];
                    $position = null;
                }
            }

            if ($i < $warmup || $position !== null) {
                continue;
            }

            // Signal on CLOSED bar only using candles[0..i]
            $tech = $this->technical->at($candles, $i);
            if (($tech['status'] ?? '') !== 'READY') {
                continue;
            }
            if ($mtfCandles) {
                $htf = $this->technical->closedHtfOnly($mtfCandles, $bar);
                if ($htf === [] && ! empty($parameters['require_mtf'])) {
                    $warnings[] = 'MTF_UNAVAILABLE_SKIP';
                    continue;
                }
                // Simple MTF filter: HTF EMA12 > EMA26 for buys
                if ($htf !== []) {
                    $htfTech = $this->technical->at($htf, count($htf) - 1);
                    $signal = $this->signalFromTech($strategyKey, $tech, $parameters);
                    if ($signal === 'BUY' && ($htfTech['ema12'] ?? 0) < ($htfTech['ema26'] ?? 0)) {
                        continue;
                    }
                    if ($signal === 'SELL' && ($htfTech['ema12'] ?? 0) > ($htfTech['ema26'] ?? 0)) {
                        continue;
                    }
                }
            }

            $signal = $this->signalFromTech($strategyKey, $tech, $parameters);
            if ($signal === null) {
                continue;
            }

            // Entry on THIS closed bar close (no future bar peek). Costs applied to mid=close.
            $mid = (float) $bar['close'];
            $atr = (float) ($tech['atr14'] ?? abs($mid) * 0.001);
            $plan = $this->risk->size($signal, $mid, $atr, $equity, $riskProfile);
            if (! $plan['approved']) {
                continue;
            }
            $entryFill = $costs->applyEntry($signal, $mid, $plan['volume']);
            $position = [
                'direction' => $signal,
                'entry' => $entryFill['price'],
                'entry_index' => $i,
                'volume' => $plan['volume'],
                'sl' => $plan['sl'],
                'initial_sl' => $plan['sl'],
                'tp' => $plan['tp'],
                'entry_costs' => $entryFill,
                'mae' => 0.0,
                'mfe' => 0.0,
                'events' => [['type' => 'ENTRY', 'price' => $entryFill['price']]],
            ];
        }

        // Force flat at end
        if ($position !== null) {
            $last = $candles[count($candles) - 1];
            $exitFill = $costs->applyExit($position['direction'], (float) $last['close'], (float) $position['volume'], 0);
            $dirBuy = strtoupper($position['direction']) === 'BUY';
            $raw = $dirBuy
                ? ($exitFill['price'] - (float) $position['entry']) * (float) $position['volume'] * 100000
                : ((float) $position['entry'] - $exitFill['price']) * (float) $position['volume'] * 100000;
            $totalCosts = $exitFill['commission'] + $exitFill['swap'] + (float) ($position['entry_costs']['commission'] ?? 0)
                + (float) ($position['entry_costs']['spread_cost'] ?? 0) + (float) ($position['entry_costs']['slippage'] ?? 0)
                + $exitFill['spread_cost'] + $exitFill['slippage'];
            $pnl = $raw - $totalCosts;
            $trades[] = [
                'entry_index' => $position['entry_index'],
                'exit_index' => count($candles) - 1,
                'direction' => $position['direction'],
                'entry' => $position['entry'],
                'exit' => $exitFill['price'],
                'volume' => $position['volume'],
                'realized_pnl' => round($pnl, 4),
                'r_multiple' => null,
                'mae' => $position['mae'] ?? null,
                'mfe' => $position['mfe'] ?? null,
                'spread_cost' => ($position['entry_costs']['spread_cost'] ?? 0) + $exitFill['spread_cost'],
                'commission' => ($position['entry_costs']['commission'] ?? 0) + $exitFill['commission'],
                'slippage' => ($position['entry_costs']['slippage'] ?? 0) + $exitFill['slippage'],
                'swap' => $exitFill['swap'],
                'break_even_applied' => ! empty($position['break_even_applied']),
                'trailing_used' => ! empty($position['trailing_used']),
                'partials_count' => (int) ($position['partials_count'] ?? 0),
                'exit_reason' => 'END_OF_DATA',
                'strategy_key' => $strategyKey,
            ];
        }

        $computed = $this->metrics->compute($trades);
        $computed['intrabar_policy'] = $intrabar->documentation();
        $computed['start_equity'] = $startEquity;
        $computed['end_equity'] = $startEquity + (float) ($computed['core']['net_pnl'] ?? 0);

        return [
            'metrics' => $computed,
            'equity_curve' => $computed['equity_curve'],
            'trades' => $trades,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Deterministic strategy signal from closed-candle technicals.
     *
     * @param  array<string, mixed>  $tech
     * @param  array<string, mixed>  $parameters
     */
    private function signalFromTech(string $strategyKey, array $tech, array $parameters): ?string
    {
        $ema12 = $tech['ema12'] ?? null;
        $ema26 = $tech['ema26'] ?? null;
        $close = $tech['last_close'] ?? null;
        $rsi = $tech['rsi14'] ?? null;
        if ($ema12 === null || $ema26 === null || $close === null) {
            return null;
        }

        return match ($strategyKey) {
            'rsi_momentum' => $this->rsiSignal($rsi, $close, $ema12),
            'ema_pullback' => ($close > $ema12 && $ema12 > $ema26 && $rsi !== null && $rsi < 45) ? 'BUY'
                : (($close < $ema12 && $ema12 < $ema26 && $rsi !== null && $rsi > 55) ? 'SELL' : null),
            default => // ema_trend and others
                ($ema12 > $ema26 && $close > $ema12) ? 'BUY'
                    : (($ema12 < $ema26 && $close < $ema12) ? 'SELL' : null),
        };
    }

    private function rsiSignal(?float $rsi, float $close, float $ema12): ?string
    {
        if ($rsi === null) {
            return null;
        }
        if ($rsi < 30 && $close > $ema12) {
            return 'BUY';
        }
        if ($rsi > 70 && $close < $ema12) {
            return 'SELL';
        }

        return null;
    }

    /** @param list<array<string, mixed>> $trades @return list<array<string, mixed>> */
    private function seededShuffle(array $trades, int $seed): array
    {
        $n = count($trades);
        if ($n <= 1) {
            return $trades;
        }
        // Deterministic LCG
        $state = $seed & 0x7fffffff;
        $idx = range(0, $n - 1);
        for ($i = $n - 1; $i > 0; $i--) {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            $j = $state % ($i + 1);
            [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
        }
        $out = [];
        foreach ($idx as $i) {
            $out[] = $trades[$i];
        }

        return $out;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function normalizeCandle(array $c): array
    {
        return [
            'open' => (float) ($c['open'] ?? $c['o'] ?? 0),
            'high' => (float) ($c['high'] ?? $c['h'] ?? 0),
            'low' => (float) ($c['low'] ?? $c['l'] ?? 0),
            'close' => (float) ($c['close'] ?? $c['c'] ?? 0),
            'open_time' => $c['open_time'] ?? $c['time'] ?? null,
            'close_time' => $c['close_time'] ?? null,
            'volume' => $c['volume'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function strategyEvaluationReport(BacktestRun $run): array
    {
        return [
            'environment' => 'BACKTEST',
            'run' => $run->public_id,
            'strategy_key' => $run->strategy_key,
            'strategy_version' => $run->strategy_version,
            'lineage_hash' => $run->lineage_hash,
            'metrics' => $run->metrics,
            'warnings' => $run->warnings,
            'auto_promote' => false,
            'recommendation' => 'Research only — does not change StrategySetting or RiskProfile',
        ];
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $this->heartbeat();

        return [
            'phase' => 12,
            'status' => 'READY',
            'engine' => self::ENGINE_VERSION,
            'environment' => 'BACKTEST',
            'order_send' => false,
            'broker_changing_calls' => 0,
            'queue' => $this->queue->stats(),
            'auto_promote_strategies' => false,
            'auto_promote_risk' => false,
            'live_execution' => 'HARD_BLOCKED',
            'intrabar_policy_default' => IntrabarPolicy::OHLC_PATH,
        ];
    }

    private function heartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'BACKTEST_ENGINE',
            'instance_id' => gethostname() ?: 'local',
            'status' => 'ONLINE',
            'environment' => 'BACKTEST',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => ['phase' => 12, 'broker_changing_calls' => 0],
            'metadata' => [],
        ]);
    }
}
