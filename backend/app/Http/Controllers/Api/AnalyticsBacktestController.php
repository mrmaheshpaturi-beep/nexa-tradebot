<?php

namespace App\Http\Controllers\Api;

use App\Analytics\AnalyticsEngineService;
use App\Backtest\BacktestEngineService;
use App\Backtest\BacktestJobQueue;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsDataset;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsSnapshot;
use App\Models\BacktestDataSnapshot;
use App\Models\BacktestRun;
use App\Models\ResearchComparison;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AnalyticsBacktestController extends Controller
{
    public function __construct(
        private readonly AnalyticsEngineService $analytics,
        private readonly BacktestEngineService $backtest,
        private readonly BacktestJobQueue $queue,
        private readonly AuditService $audit,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'data' => [
                'analytics' => $this->analytics->health(),
                'backtest' => $this->backtest->health(),
                'phase' => 12,
                'live_execution' => 'HARD_BLOCKED',
                'broker_changing_calls' => 0,
                'auto_promote_strategies' => false,
                'auto_promote_risk' => false,
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $snapId = $request->query('snapshot');
        $snapshot = $snapId
            ? AnalyticsSnapshot::query()->where('public_id', $snapId)->where('user_id', $request->user()->id)->firstOrFail()
            : null;

        return response()->json(['data' => $this->analytics->performanceDashboard($request->user(), $snapshot)]);
    }

    public function buildDataset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'source_environment' => ['nullable', 'string', 'in:DEMO,SIMULATION'],
            'filters' => ['nullable', 'array'],
        ]);
        $dataset = $this->analytics->buildDataset(
            $request->user(),
            $data['name'],
            $data['filters'] ?? [],
            $data['source_environment'] ?? 'DEMO',
            $request,
        );

        return response()->json(['data' => $dataset->load('rows')], 201);
    }

    public function datasets(Request $request): JsonResponse
    {
        $rows = AnalyticsDataset::query()
            ->where('user_id', $request->user()->id)
            ->latest('built_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function showDataset(Request $request, AnalyticsDataset $dataset): JsonResponse
    {
        abort_unless($dataset->user_id === $request->user()->id, 404);

        return response()->json(['data' => $dataset->load('rows')]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:160'],
        ]);
        $dataset = AnalyticsDataset::query()
            ->where('public_id', $data['dataset_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $snap = $this->analytics->snapshot($request->user(), $dataset, $data['label'] ?? null, $request);

        return response()->json(['data' => $snap], 201);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $rows = AnalyticsSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->latest('captured_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function tradeExplorer(Request $request): JsonResponse
    {
        $datasetId = $request->query('dataset_id');
        $q = \App\Models\AnalyticsDatasetRow::query()->whereHas('dataset', fn ($b) => $b->where('user_id', $request->user()->id));
        if ($datasetId) {
            $q->whereHas('dataset', fn ($b) => $b->where('public_id', $datasetId));
        }
        $rows = $q->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function exportSnapshot(Request $request, AnalyticsSnapshot $snapshot): Response|JsonResponse
    {
        abort_unless($snapshot->user_id === $request->user()->id, 404);
        $format = strtolower((string) $request->query('format', 'json'));
        $exported = $this->analytics->exportSnapshot($snapshot, $request->user());
        $this->audit->record('analytics.exported', $snapshot, [], ['format' => $format], $request);
        if ($format === 'csv') {
            return response($exported['csv'], 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$snapshot->public_id.'.csv"',
            ]);
        }

        return response()->json(['data' => json_decode($exported['json'], true)]);
    }

    public function createReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:48'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'array'],
            'snapshot_id' => ['nullable', 'string'],
        ]);
        $snap = null;
        if (! empty($data['snapshot_id'])) {
            $snap = AnalyticsSnapshot::query()
                ->where('public_id', $data['snapshot_id'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
        }
        $report = $this->analytics->createReport(
            $request->user(),
            $data['report_type'],
            $data['title'],
            $data['body'],
            $snap,
            $request,
        );

        return response()->json(['data' => $report], 201);
    }

    public function reports(Request $request): JsonResponse
    {
        $rows = AnalyticsReport::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }

    public function createDataSnapshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:64'],
            'timeframe' => ['required', 'string', 'max:16'],
            'candles' => ['required', 'array', 'min:30'],
            'mtf_candles' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:48'],
        ]);
        $snap = $this->backtest->createDataSnapshot(
            $request->user(),
            $data['symbol'],
            $data['timeframe'],
            $data['candles'],
            $data['mtf_candles'] ?? null,
            $data['source'] ?? 'INLINE',
        );
        $this->audit->record('backtest.data_snapshot', $snap, [], ['hash' => $snap->content_hash], $request);

        return response()->json(['data' => $snap], 201);
    }

    public function queueBacktest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data_snapshot_id' => ['required', 'string'],
            'strategy_key' => ['required', 'string', 'max:80'],
            'parameters' => ['nullable', 'array'],
            'cost_model' => ['nullable', 'array'],
            'risk_profile' => ['nullable', 'array'],
            'management_policy' => ['nullable', 'array'],
            'intrabar_policy' => ['nullable', 'string', 'max:48'],
            'run_kind' => ['nullable', 'string', 'in:SINGLE,PORTFOLIO,WALK_FORWARD,OPTIMIZATION,MONTE_CARLO'],
            'seed' => ['nullable', 'integer'],
            'process_now' => ['nullable', 'boolean'],
        ]);
        $snapshot = BacktestDataSnapshot::query()
            ->where('public_id', $data['data_snapshot_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $run = $this->backtest->queueRun(
            $request->user(),
            $snapshot,
            $data['strategy_key'],
            $data['parameters'] ?? [],
            $data['cost_model'] ?? [],
            $data['risk_profile'] ?? [],
            $data['management_policy'] ?? [],
            $data['intrabar_policy'] ?? 'OHLC_PATH',
            $data['run_kind'] ?? 'SINGLE',
            $data['seed'] ?? null,
            $request,
        );
        if ($request->boolean('process_now', true)) {
            $this->backtest->processNextJobs(5);
            $run = $run->fresh();
        }

        return response()->json(['data' => $run], 201);
    }

    public function processQueue(Request $request): JsonResponse
    {
        $limit = min(10, max(1, (int) $request->input('limit', 3)));
        $results = $this->backtest->processNextJobs($limit);
        $this->audit->record('backtest.queue_processed', $request->user(), [], ['results' => count($results)], $request);

        return response()->json(['data' => ['results' => $results, 'queue' => $this->queue->stats($request->user())]]);
    }

    public function runs(Request $request): JsonResponse
    {
        $rows = BacktestRun::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }

    public function showRun(Request $request, BacktestRun $run): JsonResponse
    {
        abort_unless($run->user_id === $request->user()->id, 404);

        return response()->json(['data' => $run->load(['folds', 'trials', 'monteCarloPaths'])]);
    }

    public function strategyEvaluation(Request $request, BacktestRun $run): JsonResponse
    {
        abort_unless($run->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->backtest->strategyEvaluationReport($run)]);
    }

    public function exportRun(Request $request, BacktestRun $run): Response|JsonResponse
    {
        abort_unless($run->user_id === $request->user()->id, 404);
        $format = strtolower((string) $request->query('format', 'json'));
        $this->audit->record('backtest.exported', $run, [], ['format' => $format], $request);
        if ($format === 'csv') {
            $csv = "entry_index,exit_index,direction,realized_pnl,r_multiple,mae,mfe,exit_reason\n";
            foreach ($run->trades ?? [] as $t) {
                $csv .= implode(',', [
                    $t['entry_index'] ?? '',
                    $t['exit_index'] ?? '',
                    $t['direction'] ?? '',
                    $t['realized_pnl'] ?? '',
                    $t['r_multiple'] ?? '',
                    $t['mae'] ?? '',
                    $t['mfe'] ?? '',
                    $t['exit_reason'] ?? '',
                ])."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$run->public_id.'.csv"',
            ]);
        }

        return response()->json(['data' => [
            'run' => $run->public_id,
            'environment' => 'BACKTEST',
            'lineage' => $run->lineage,
            'metrics' => $run->metrics,
            'trades' => $run->trades,
        ]]);
    }

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backtest_run_id' => ['required', 'string'],
            'demo_snapshot_id' => ['required', 'string'],
        ]);
        $run = BacktestRun::query()
            ->where('public_id', $data['backtest_run_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $snap = AnalyticsSnapshot::query()
            ->where('public_id', $data['demo_snapshot_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $cmp = $this->analytics->compareBacktestToDemo($request->user(), $run, $snap, $request);

        return response()->json(['data' => $cmp], 201);
    }

    public function comparisons(Request $request): JsonResponse
    {
        $rows = ResearchComparison::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }

    public function queueStats(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queue->stats($request->user())]);
    }

    /** Explicitly refuse any promote attempt. */
    public function refusePromote(Request $request): JsonResponse
    {
        $this->audit->record('analytics.promote_refused', $request->user(), [], [
            'reason' => 'Phase 12 must not auto-change or promote strategies/risk',
        ], $request);

        return response()->json([
            'message' => 'Promotion refused. Phase 12 never promotes strategies or risk profiles from research results.',
            'auto_promote' => false,
        ], 403);
    }
}
