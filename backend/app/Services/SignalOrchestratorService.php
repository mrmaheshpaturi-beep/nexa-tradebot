<?php

namespace App\Services;

use App\Models\SignalCandidate;
use App\Models\ScannerRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Signal Orchestrator — aggregate, prioritize, conflict-detect, queue candidates.
 * NEVER routes to broker. Candidates are opportunities, not orders.
 */
class SignalOrchestratorService
{
    public function __construct(
        private readonly AlertPipelineService $alerts,
    ) {}

    /**
     * Ingest scan evaluation rows into the candidate queue.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, replayed: int, candidates: list<SignalCandidate>}
     */
    public function ingest(User $user, ScannerRun $run, array $rows): array
    {
        $created = 0;
        $replayed = 0;
        $candidates = [];

        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'SIGNAL' && empty($row['signal_id'])) {
                continue;
            }
            $direction = strtoupper((string) ($row['direction'] ?? 'NEUTRAL'));
            if (! in_array($direction, ['BUY', 'SELL'], true)) {
                continue;
            }

            $symbol = strtoupper((string) $row['symbol']);
            $timeframe = strtoupper((string) $row['timeframe']);
            $plugin = (string) ($row['plugin_key'] ?? 'unknown');
            $candleKey = (string) ($row['candle_close_key'] ?? 'na');
            $fingerprint = hash('sha256', implode('|', [
                $user->id,
                $row['trading_strategy_id'] ?? 'scan',
                $plugin,
                $symbol,
                $timeframe,
                $direction,
                $candleKey,
                (string) ($row['configuration_version'] ?? 1),
            ]));

            $existing = SignalCandidate::query()
                ->where('user_id', $user->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing) {
                $existing->scanner_run_id = $run->id;
                $existing->rank_score = $this->rankScore($row);
                $existing->confluence_score = $row['confluence_score'] ?? $existing->confluence_score;
                $existing->confluence = $row['confluence'] ?? $existing->confluence;
                $existing->quality = $row['quality'] ?? $existing->quality;
                $existing->freshness = $row['freshness'] ?? $existing->freshness;
                if ($existing->status === 'QUEUED') {
                    $existing->status = 'ACTIVE';
                }
                $existing->save();
                $candidates[] = $existing;
                $replayed++;
                continue;
            }

            $candidate = SignalCandidate::query()->create([
                'user_id' => $user->id,
                'scanner_run_id' => $run->id,
                'signal_id' => $row['signal_id'] ?? null,
                'trading_strategy_id' => $row['trading_strategy_id'] ?? null,
                'plugin_key' => $plugin,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'direction' => $direction,
                'status' => 'ACTIVE',
                'rank_score' => $this->rankScore($row),
                'priority' => (int) ($row['priority'] ?? 100),
                'confluence_score' => $row['confluence_score'] ?? ($row['raw_score'] ?? 0),
                'candle_close_key' => $candleKey,
                'fingerprint' => $fingerprint,
                'conflict_group' => $symbol.'|'.$timeframe,
                'conflict_flags' => [],
                'score_breakdown' => $row['score_breakdown'] ?? null,
                'confluence' => $row['confluence'] ?? null,
                'evidence' => $row['evidence'] ?? null,
                'quality' => $row['quality'] ?? null,
                'freshness' => $row['freshness'] ?? null,
                'marked_for_simulate' => (bool) ($row['marked_for_simulate'] ?? false),
                'expires_at' => isset($row['expires_at'])
                    ? Carbon::parse($row['expires_at'])
                    : Carbon::now('UTC')->addMinutes((int) ($row['expiry_minutes'] ?? 120)),
                'metadata' => [
                    'phase' => 8,
                    'broker_routing' => false,
                    'disclaimer' => 'Candidate is not an order. Scores are not win probabilities.',
                ],
            ]);

            $candidates[] = $candidate;
            $created++;

            $this->alerts->emit(
                $user,
                'CANDIDATE_CREATED',
                "Candidate {$direction} {$symbol} {$timeframe}",
                "Plugin {$plugin} ranked ".number_format((float) $candidate->rank_score, 1),
                'INFO',
                'IN_APP',
                $candidate,
                $run,
                ['fingerprint' => $fingerprint],
            );
        }

        $this->detectConflicts($user, collect($candidates)->pluck('conflict_group')->unique()->filter()->all());

        return [
            'created' => $created,
            'replayed' => $replayed,
            'candidates' => $candidates,
        ];
    }

    /**
     * Ranked candidate queue for dashboard — not an order book.
     *
     * @return array<string, mixed>
     */
    public function queue(User $user, array $filters = []): array
    {
        $this->expireDue($user);

        $query = SignalCandidate::query()
            ->where('user_id', $user->id)
            ->with(['strategy:id,name,plugin_key', 'signal:id,public_id,status,score']);

        $status = $filters['status'] ?? null;
        if ($status) {
            $query->where('status', strtoupper($status));
        } else {
            $query->whereIn('status', ['QUEUED', 'ACTIVE']);
        }
        if (! empty($filters['symbol'])) {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }
        if (! empty($filters['timeframe'])) {
            $query->where('timeframe', strtoupper((string) $filters['timeframe']));
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', strtoupper((string) $filters['direction']));
        }
        if (! empty($filters['plugin_key'])) {
            $query->where('plugin_key', $filters['plugin_key']);
        }

        $limit = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $rows = $query->orderByDesc('rank_score')->orderBy('priority')->orderByDesc('id')->limit($limit)->get();

        return [
            'phase' => 8,
            'mode' => 'CANDIDATE_QUEUE_NOT_ORDERS',
            'count' => $rows->count(),
            'candidates' => $rows,
            'execution' => $this->executionFlags(),
            'disclaimer' => 'Candidates are ranked opportunities for analysis. They are not broker orders.',
        ];
    }

    public function dismiss(User $user, SignalCandidate $candidate): SignalCandidate
    {
        abort_unless($candidate->user_id === $user->id, 404);
        $candidate->status = 'DISMISSED';
        $candidate->dismissed_at = Carbon::now('UTC');
        $candidate->save();
        $this->alerts->emit($user, 'CANDIDATE_DISMISSED', 'Candidate dismissed', $candidate->public_id, 'INFO', 'IN_APP', $candidate);

        return $candidate;
    }

    public function invalidate(User $user, SignalCandidate $candidate, string $reason = 'MANUAL'): SignalCandidate
    {
        abort_unless($candidate->user_id === $user->id, 404);
        $candidate->status = 'INVALIDATED';
        $candidate->invalidated_at = Carbon::now('UTC');
        $candidate->invalidation_reason = $reason;
        $candidate->save();
        $this->alerts->emit($user, 'CANDIDATE_INVALIDATED', 'Candidate invalidated', $reason, 'WARN', 'IN_APP', $candidate);

        return $candidate;
    }

    /**
     * Mark candidate for future simulation — never MT5 / broker.
     */
    public function markForSimulate(User $user, SignalCandidate $candidate): SignalCandidate
    {
        abort_unless($candidate->user_id === $user->id, 404);
        $candidate->marked_for_simulate = true;
        $meta = $candidate->metadata ?? [];
        $meta['simulate_marked_at'] = Carbon::now('UTC')->toIso8601String();
        $meta['simulate_target'] = 'SIMULATION_ONLY';
        $meta['mt5_execution'] = false;
        $candidate->metadata = $meta;
        $candidate->save();
        $this->alerts->emit(
            $user,
            'CANDIDATE_MARKED_SIMULATE',
            'Candidate marked for simulation',
            $candidate->public_id,
            'INFO',
            'IN_APP',
            $candidate,
            null,
            ['simulate_target' => 'SIMULATION_ONLY', 'mt5' => false],
        );

        return $candidate;
    }

    public function expireDue(?User $user = null): int
    {
        $query = SignalCandidate::query()
            ->whereIn('status', ['QUEUED', 'ACTIVE'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now('UTC'));
        if ($user) {
            $query->where('user_id', $user->id);
        }
        $count = 0;
        foreach ($query->get() as $candidate) {
            $candidate->status = 'EXPIRED';
            $candidate->save();
            $count++;
        }

        return $count;
    }

    /**
     * Detect opposing-direction conflicts within conflict groups.
     *
     * @param  list<string>  $groups
     */
    public function detectConflicts(User $user, array $groups = []): array
    {
        $query = SignalCandidate::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['QUEUED', 'ACTIVE']);
        if ($groups !== []) {
            $query->whereIn('conflict_group', $groups);
        }
        $byGroup = $query->get()->groupBy('conflict_group');
        $conflicts = [];

        foreach ($byGroup as $group => $items) {
            /** @var Collection<int, SignalCandidate> $items */
            $dirs = $items->pluck('direction')->unique()->filter()->values();
            if ($dirs->count() < 2) {
                foreach ($items as $item) {
                    if ($item->conflict_flags) {
                        $item->conflict_flags = array_values(array_filter(
                            $item->conflict_flags ?? [],
                            fn ($f) => ($f['code'] ?? '') !== 'OPPOSING_DIRECTION',
                        ));
                        $item->save();
                    }
                }
                continue;
            }
            $peerIds = $items->pluck('public_id')->all();
            foreach ($items as $item) {
                $flags = $item->conflict_flags ?? [];
                $flags = array_values(array_filter($flags, fn ($f) => ($f['code'] ?? '') !== 'OPPOSING_DIRECTION'));
                $flags[] = [
                    'code' => 'OPPOSING_DIRECTION',
                    'detail' => 'Multiple strategies disagree on direction for '.$group,
                    'peers' => $peerIds,
                ];
                // Soft priority penalty for conflicts
                $item->conflict_flags = $flags;
                $item->priority = max(1, (int) $item->priority + 25);
                $item->rank_score = max(0, (float) $item->rank_score - 8);
                $item->save();
            }
            $conflicts[] = ['group' => $group, 'directions' => $dirs->all(), 'count' => $items->count()];
        }

        // Multi-strategy same-direction: note only (no hard block)
        foreach ($byGroup as $group => $items) {
            if ($items->count() < 2) {
                continue;
            }
            $sameDir = $items->groupBy('direction');
            foreach ($sameDir as $direction => $peers) {
                if ($peers->count() < 2) {
                    continue;
                }
                foreach ($peers as $item) {
                    $flags = $item->conflict_flags ?? [];
                    $has = collect($flags)->contains(fn ($f) => ($f['code'] ?? '') === 'MULTI_STRATEGY_SAME_DIRECTION');
                    if (! $has) {
                        $flags[] = [
                            'code' => 'MULTI_STRATEGY_SAME_DIRECTION',
                            'detail' => 'Multiple plugins agree '.$direction.' on '.$group,
                            'peers' => $peers->pluck('public_id')->all(),
                        ];
                        $item->conflict_flags = $flags;
                        $item->save();
                    }
                }
            }
        }

        return $conflicts;
    }

    /** @return array<string, mixed> */
    public function health(User $user): array
    {
        $active = SignalCandidate::query()->where('user_id', $user->id)->whereIn('status', ['QUEUED', 'ACTIVE'])->count();
        $conflicts = SignalCandidate::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['QUEUED', 'ACTIVE'])
            ->whereNotNull('conflict_flags')
            ->get()
            ->filter(fn ($c) => ! empty($c->conflict_flags))
            ->count();

        return [
            'service' => 'SIGNAL_ORCHESTRATOR',
            'phase' => 8,
            'active_candidates' => $active,
            'conflicted_candidates' => $conflicts,
            'broker_routing' => false,
            'execution' => $this->executionFlags(),
            'status' => 'READY',
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function rankScore(array $row): float
    {
        $score = (float) ($row['confluence_score'] ?? $row['raw_score'] ?? 0);
        $quality = (float) ($row['quality']['score'] ?? $row['data_quality'] ?? 80);
        $freshPenalty = (($row['freshness']['stale'] ?? false) === true) ? 15 : 0;
        $conflictPenalty = (float) ($row['conflict_penalty'] ?? 0);

        return max(0, min(100, $score * 0.7 + ($quality / 100) * 30 - $freshPenalty - $conflictPenalty));
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
            'mode' => 'CANDIDATES_ONLY',
        ];
    }
}
