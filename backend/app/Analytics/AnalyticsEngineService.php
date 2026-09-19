<?php

namespace App\Analytics;

use App\Models\AnalyticsDataset;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsSnapshot;
use App\Models\ResearchComparison;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Enums\TradingEnvironment;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Phase 12 AnalyticsEngine — research/reporting over DEMO/SIMULATION outcomes.
 * Zero broker-changing calls. Never auto-promotes strategies or risk.
 */
class AnalyticsEngineService
{
    public function __construct(
        private readonly DatasetBuilder $datasets,
        private readonly MetricsCalculator $metrics,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildDataset(User $user, string $name, array $filters = [], string $sourceEnvironment = 'DEMO', ?Request $request = null): AnalyticsDataset
    {
        $dataset = $this->datasets->build($user, $name, $filters, $sourceEnvironment);
        $this->heartbeat('ANALYTICS_ENGINE');
        $this->audit->record('analytics.dataset_built', $dataset, [], [
            'content_hash' => $dataset->content_hash,
            'row_count' => $dataset->row_count,
            'source_environment' => $dataset->source_environment,
        ], $request);

        return $dataset;
    }

    public function snapshot(User $user, AnalyticsDataset $dataset, ?string $label = null, ?Request $request = null): AnalyticsSnapshot
    {
        abort_unless($dataset->user_id === $user->id, 404);
        $computed = $this->metrics->compute($this->rowsForMetrics($dataset));
        $lineage = [
            'dataset_public_id' => $dataset->public_id,
            'dataset_content_hash' => $dataset->content_hash,
            'source_environment' => $dataset->source_environment,
            'row_count' => $dataset->row_count,
            'engine' => 'AnalyticsEngine/v1',
            'auto_promote' => false,
            'broker_changing_calls' => 0,
        ];
        $contentHash = hash('sha256', json_encode([
            'dataset' => $dataset->content_hash,
            'metrics' => $computed,
        ], JSON_THROW_ON_ERROR));

        $snap = AnalyticsSnapshot::query()->create([
            'user_id' => $user->id,
            'analytics_dataset_id' => $dataset->id,
            'label' => $label,
            'content_hash' => $contentHash,
            'metrics' => $computed,
            'lineage' => $lineage,
            'immutable' => true,
            'captured_at' => now(),
        ]);

        $this->heartbeat('ANALYTICS_ENGINE');
        $this->audit->record('analytics.snapshot_captured', $snap, [], [
            'content_hash' => $contentHash,
            'dataset' => $dataset->public_id,
        ], $request);

        return $snap;
    }

    /** @return array<string, mixed> */
    public function performanceDashboard(User $user, ?AnalyticsSnapshot $snapshot = null): array
    {
        if ($snapshot === null) {
            $snapshot = AnalyticsSnapshot::query()->where('user_id', $user->id)->latest('captured_at')->first();
        } else {
            abort_unless($snapshot->user_id === $user->id, 404);
        }

        return [
            'environment_note' => 'Analytics reflect DEMO/SIMULATION completed outcomes only. BACKTEST is separate.',
            'snapshot' => $snapshot,
            'metrics' => $snapshot?->metrics,
            'auto_promote_strategies' => false,
            'auto_promote_risk' => false,
            'live_execution' => 'HARD_BLOCKED',
            'broker_changing_calls' => 0,
        ];
    }

    public function createReport(User $user, string $type, string $title, array $body, ?AnalyticsSnapshot $snapshot = null, ?Request $request = null): AnalyticsReport
    {
        $report = AnalyticsReport::query()->create([
            'user_id' => $user->id,
            'analytics_snapshot_id' => $snapshot?->id,
            'report_type' => strtoupper($type),
            'title' => $title,
            'body' => $body,
        ]);
        $this->audit->record('analytics.report_created', $report, [], ['type' => $type], $request);

        return $report;
    }

    /**
     * Strict BACKTEST vs DEMO comparison — labels never mixed/substituted.
     */
    public function compareBacktestToDemo(
        User $user,
        \App\Models\BacktestRun $backtestRun,
        AnalyticsSnapshot $demoSnapshot,
        ?Request $request = null,
    ): ResearchComparison {
        abort_unless($backtestRun->user_id === $user->id, 404);
        abort_unless($demoSnapshot->user_id === $user->id, 404);
        abort_unless($backtestRun->environment === TradingEnvironment::Backtest, 422);

        $bt = $backtestRun->metrics ?? [];
        $demo = $demoSnapshot->metrics ?? [];
        $warnings = [];
        if (($demoSnapshot->dataset->source_environment ?? '') === 'BACKTEST') {
            $warnings[] = 'DEMO snapshot source unexpectedly BACKTEST — rejected path should prevent this';
        }

        $comparison = [
            'BACKTEST' => [
                'label' => 'BACKTEST',
                'run_public_id' => $backtestRun->public_id,
                'lineage_hash' => $backtestRun->lineage_hash,
                'metrics' => $bt,
            ],
            'DEMO' => [
                'label' => 'DEMO',
                'snapshot_public_id' => $demoSnapshot->public_id,
                'content_hash' => $demoSnapshot->content_hash,
                'metrics' => $demo,
            ],
            'deltas' => [
                'net_pnl' => $this->delta($bt['core']['net_pnl'] ?? null, $demo['core']['net_pnl'] ?? null),
                'win_rate' => $this->delta($bt['core']['win_rate'] ?? null, $demo['core']['win_rate'] ?? null),
                'avg_r' => $this->delta($bt['r_multiple']['avg_r'] ?? null, $demo['r_multiple']['avg_r'] ?? null),
                'max_drawdown' => $this->delta($bt['risk_adjusted']['max_drawdown'] ?? null, $demo['risk_adjusted']['max_drawdown'] ?? null),
            ],
            'separation' => [
                'labels_distinct' => true,
                'silent_substitution' => false,
                'environments' => ['BACKTEST', 'DEMO'],
            ],
            'auto_promote' => false,
        ];

        $row = ResearchComparison::query()->create([
            'user_id' => $user->id,
            'backtest_run_id' => $backtestRun->id,
            'analytics_snapshot_id' => $demoSnapshot->id,
            'backtest_label' => 'BACKTEST',
            'demo_label' => 'DEMO',
            'comparison' => $comparison,
            'warnings' => $warnings,
        ]);

        $this->audit->record('analytics.backtest_demo_comparison', $row, [], [
            'backtest' => $backtestRun->public_id,
            'demo' => $demoSnapshot->public_id,
        ], $request);

        return $row;
    }

    /** @return array{csv:string,json:string} */
    public function exportSnapshot(AnalyticsSnapshot $snapshot, User $user): array
    {
        abort_unless($snapshot->user_id === $user->id, 404);
        $rows = $snapshot->dataset->rows()->orderBy('row_index')->get();
        $csv = "row_index,symbol,direction,realized_pnl,r_multiple,mae,mfe,strategy_key,session,timeframe,regime\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r->row_index,
                $this->csv($r->symbol),
                $this->csv($r->direction),
                $r->realized_pnl,
                $r->r_multiple,
                $r->mae,
                $r->mfe,
                $this->csv($r->strategy_key),
                $this->csv($r->session),
                $this->csv($r->timeframe),
                $this->csv($r->regime),
            ])."\n";
        }

        return [
            'csv' => $csv,
            'json' => json_encode([
                'snapshot' => $snapshot->public_id,
                'content_hash' => $snapshot->content_hash,
                'metrics' => $snapshot->metrics,
                'rows' => $rows->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $this->heartbeat('ANALYTICS_ENGINE');

        return [
            'phase' => 12,
            'status' => 'READY',
            'engine' => 'AnalyticsEngine/v1',
            'order_send' => false,
            'broker_changing_calls' => 0,
            'auto_promote_strategies' => false,
            'auto_promote_risk' => false,
            'live_execution' => 'HARD_BLOCKED',
            'environments' => ['DEMO', 'SIMULATION', 'BACKTEST_SEPARATE'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rowsForMetrics(AnalyticsDataset $dataset): array
    {
        return $dataset->rows()->orderBy('row_index')->get()->map(function ($r) {
            $ts = $r->payload['trade_summary'] ?? [];

            return [
                'realized_pnl' => $r->realized_pnl !== null ? (float) $r->realized_pnl : null,
                'r_multiple' => $r->r_multiple !== null ? (float) $r->r_multiple : null,
                'mae' => $r->mae !== null ? (float) $r->mae : null,
                'mfe' => $r->mfe !== null ? (float) $r->mfe : null,
                'spread_cost' => $r->spread_cost !== null ? (float) $r->spread_cost : null,
                'commission' => $r->commission !== null ? (float) $r->commission : null,
                'slippage' => $r->slippage !== null ? (float) $r->slippage : null,
                'swap' => $r->swap !== null ? (float) $r->swap : null,
                'break_even_applied' => (bool) ($ts['break_even_applied'] ?? false),
                'trailing_used' => (bool) ($ts['trailing_used'] ?? false),
                'partials_count' => (int) ($ts['partials_count'] ?? 0),
            ];
        })->all();
    }

    private function delta(mixed $a, mixed $b): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        return round((float) $a - (float) $b, 8);
    }

    private function csv(?string $v): string
    {
        $v = (string) $v;
        if (str_contains($v, ',') || str_contains($v, '"')) {
            return '"'.str_replace('"', '""', $v).'"';
        }

        return $v;
    }

    private function heartbeat(string $service): void
    {
        ServiceHeartbeat::query()->create([
            'service' => $service,
            'instance_id' => gethostname() ?: 'local',
            'status' => 'ONLINE',
            'environment' => 'SIMULATION',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => ['phase' => 12, 'broker_changing_calls' => 0],
            'metadata' => [],
        ]);
    }
}
