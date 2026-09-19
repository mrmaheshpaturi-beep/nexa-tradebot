<?php

namespace App\Analytics;

use App\Models\AnalyticsDataset;
use App\Models\AnalyticsDatasetRow;
use App\Models\ExecutionResult;
use App\Models\ManagedPosition;
use App\Models\RiskDecision;
use App\Models\Signal;
use App\Models\SignalCandidate;
use App\Models\TradeManagementEvent;
use App\Models\TradeSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds reproducible analytics datasets from TradeSummary + lineage sources.
 * Never calls broker APIs. Never mutates strategies/risk policies.
 */
class DatasetBuilder
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(User $user, string $name, array $filters = [], string $sourceEnvironment = 'DEMO'): AnalyticsDataset
    {
        $sourceEnvironment = strtoupper($sourceEnvironment);
        if ($sourceEnvironment === 'LIVE') {
            abort(422, 'LIVE source environment is not allowed for analytics datasets.');
        }
        if ($sourceEnvironment === 'BACKTEST') {
            abort(422, 'Use BacktestEngine for BACKTEST results; analytics datasets are DEMO/SIMULATION outcomes.');
        }

        $query = TradeSummary::query()
            ->where('user_id', $user->id)
            ->where('finalized', true)
            ->orderBy('finalized_at')
            ->orderBy('id');

        if (! empty($filters['symbol'])) {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }
        if (! empty($filters['from'])) {
            $query->where('finalized_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('finalized_at', '<=', $filters['to']);
        }

        $summaries = $query->get();
        $rows = [];
        foreach ($summaries as $i => $summary) {
            $rows[] = $this->hydrateRow($summary, $i);
        }

        $fingerprint = [
            'user_id' => $user->id,
            'source_environment' => $sourceEnvironment,
            'filters' => $filters,
            'summary_public_ids' => array_map(fn ($r) => $r['trade_summary_public_id'], $rows),
            'row_count' => count($rows),
        ];
        $contentHash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $name, $sourceEnvironment, $filters, $fingerprint, $contentHash, $rows) {
            $dataset = AnalyticsDataset::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'source_environment' => $sourceEnvironment,
                'status' => 'READY',
                'row_count' => count($rows),
                'filters' => $filters,
                'fingerprint' => $fingerprint,
                'content_hash' => $contentHash,
                'built_at' => now(),
            ]);

            foreach ($rows as $row) {
                AnalyticsDatasetRow::query()->create([
                    'analytics_dataset_id' => $dataset->id,
                    'row_index' => $row['row_index'],
                    'trade_summary_public_id' => $row['trade_summary_public_id'],
                    'symbol' => $row['symbol'],
                    'direction' => $row['direction'],
                    'strategy_key' => $row['strategy_key'],
                    'strategy_version' => $row['strategy_version'],
                    'session' => $row['session'],
                    'timeframe' => $row['timeframe'],
                    'regime' => $row['regime'],
                    'realized_pnl' => $row['realized_pnl'],
                    'r_multiple' => $row['r_multiple'],
                    'mae' => $row['mae'],
                    'mfe' => $row['mfe'],
                    'spread_cost' => $row['spread_cost'],
                    'commission' => $row['commission'],
                    'slippage' => $row['slippage'],
                    'swap' => $row['swap'],
                    'payload' => $row['payload'],
                ]);
            }

            return $dataset->fresh(['rows']);
        });
    }

    /** @return array<string, mixed> */
    private function hydrateRow(TradeSummary $summary, int $index): array
    {
        $position = ManagedPosition::query()->find($summary->managed_position_id);
        $events = TradeManagementEvent::query()
            ->where('managed_position_id', $summary->managed_position_id)
            ->orderBy('occurred_at')
            ->get();

        $meta = is_array($position?->metadata) ? $position->metadata : [];
        $strategyKey = $meta['strategy_key'] ?? $meta['plugin_key'] ?? null;
        $strategyVersion = $meta['strategy_version'] ?? $position?->management_policy_version;
        $session = $meta['session'] ?? $this->inferSession($summary->opened_at);
        $timeframe = $meta['timeframe'] ?? null;
        $regime = $meta['regime'] ?? null;

        $signal = null;
        $candidate = null;
        $risk = null;
        $execution = null;
        if (! empty($meta['signal_id'])) {
            $signal = Signal::query()->find($meta['signal_id']);
        }
        if (! empty($meta['candidate_id'])) {
            $candidate = SignalCandidate::query()->find($meta['candidate_id']);
        }
        if (! empty($meta['risk_decision_id'])) {
            $risk = RiskDecision::query()->find($meta['risk_decision_id']);
        }
        if (! empty($meta['execution_result_id'])) {
            $execution = ExecutionResult::query()->find($meta['execution_result_id']);
        }

        $costs = [
            'spread_cost' => $meta['spread_cost'] ?? null,
            'commission' => $meta['commission'] ?? null,
            'slippage' => $meta['slippage'] ?? null,
            'swap' => $meta['swap'] ?? null,
        ];

        return [
            'row_index' => $index,
            'trade_summary_public_id' => $summary->public_id,
            'symbol' => $summary->symbol,
            'direction' => $summary->direction?->value ?? (string) $summary->direction,
            'strategy_key' => $strategyKey,
            'strategy_version' => $strategyVersion !== null ? (string) $strategyVersion : null,
            'session' => $session,
            'timeframe' => $timeframe,
            'regime' => $regime,
            'realized_pnl' => $summary->realized_pnl !== null ? (float) $summary->realized_pnl : null,
            'r_multiple' => $summary->r_multiple !== null ? (float) $summary->r_multiple : null,
            'mae' => $summary->mae !== null ? (float) $summary->mae : null,
            'mfe' => $summary->mfe !== null ? (float) $summary->mfe : null,
            'spread_cost' => $costs['spread_cost'],
            'commission' => $costs['commission'],
            'slippage' => $costs['slippage'],
            'swap' => $costs['swap'],
            'break_even_applied' => (bool) $summary->break_even_applied,
            'trailing_used' => (bool) $summary->trailing_used,
            'partials_count' => (int) $summary->partials_count,
            'payload' => [
                'trade_summary' => [
                    'public_id' => $summary->public_id,
                    'entry_price' => $summary->entry_price,
                    'exit_price' => $summary->exit_price,
                    'close_reason' => $summary->close_reason?->value ?? $summary->close_reason,
                    'break_even_applied' => (bool) $summary->break_even_applied,
                    'trailing_used' => (bool) $summary->trailing_used,
                    'partials_count' => (int) $summary->partials_count,
                    'opened_at' => optional($summary->opened_at)?->toIso8601String(),
                    'closed_at' => optional($summary->closed_at)?->toIso8601String(),
                    'timeline' => $summary->timeline,
                ],
                'signal' => $signal ? ['public_id' => $signal->public_id, 'id' => $signal->id] : null,
                'candidate' => $candidate ? ['public_id' => $candidate->public_id ?? null, 'id' => $candidate->id] : null,
                'risk_decision' => $risk ? ['public_id' => $risk->public_id, 'status' => $risk->status?->value ?? null] : null,
                'execution_result' => $execution ? ['id' => $execution->id, 'public_id' => $execution->public_id ?? null] : null,
                'management_events' => $events->map(fn ($e) => [
                    'event_type' => $e->event_type,
                    'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                    'payload' => $e->payload,
                ])->all(),
                'mae' => $summary->mae,
                'mfe' => $summary->mfe,
                'r_multiple' => $summary->r_multiple,
                'net_pnl' => $summary->realized_pnl,
                'market_regime' => $regime,
                'session' => $session,
                'timeframe' => $timeframe,
            ],
        ];
    }

    private function inferSession(?\DateTimeInterface $openedAt): ?string
    {
        if ($openedAt === null) {
            return null;
        }
        $h = (int) $openedAt->format('H');
        if ($h >= 0 && $h < 7) {
            return 'ASIA';
        }
        if ($h >= 7 && $h < 13) {
            return 'LONDON';
        }
        if ($h >= 13 && $h < 21) {
            return 'NEW_YORK';
        }

        return 'OFF_HOURS';
    }
}
