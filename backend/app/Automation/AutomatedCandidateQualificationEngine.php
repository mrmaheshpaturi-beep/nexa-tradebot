<?php

namespace App\Automation;

use App\Enums\AutomationWorkflowState;
use App\Models\AutomationProfile;
use App\Models\AutomationSession;
use App\Models\AutomationWorkflow;
use App\Models\IntelligenceAssessment;
use App\Models\Position;
use App\Models\SignalCandidate;
use App\Enums\PositionStatus;
use Illuminate\Support\Str;

/**
 * Deterministic qualification. AI is NOT final authority.
 * Intelligence failure → WAIT / EXPIRE / REJECT — never silent bypass.
 */
class AutomatedCandidateQualificationEngine
{
    public function __construct(
        private readonly EconomicEventTradePolicy $calendarPolicy,
        private readonly AutomationExecutionLockService $locks,
    ) {}

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{decision:string,code:?string,detail:?string,qualification:array<string,mixed>,next_state:AutomationWorkflowState}
     */
    public function qualify(
        AutomationSession $session,
        AutomationProfile $profile,
        array $candidate,
        ?IntelligenceAssessment $intelligence = null,
    ): array {
        $symbol = strtoupper((string) ($candidate['symbol'] ?? ''));
        $direction = strtoupper((string) ($candidate['direction'] ?? $candidate['side'] ?? ''));
        $strategyKey = (string) ($candidate['strategy_key'] ?? $candidate['strategy'] ?? '');
        $strategyVersion = (string) ($candidate['strategy_version'] ?? 'v1');
        $timeframe = (string) ($candidate['timeframe'] ?? '');
        $confluence = (float) ($candidate['confluence_score'] ?? $candidate['rank_score'] ?? 0);
        $rules = $profile->qualification_rules ?? [];
        $minConfluence = (float) ($rules['min_confluence'] ?? 60);
        $aiFailure = strtoupper((string) ($rules['ai_failure_policy'] ?? 'WAIT'));
        $intelRequired = (bool) ($profile->intelligence_required ?? ($rules['intelligence_required'] ?? true));

        $steps = [];

        // Universe / matrix
        if (! in_array($symbol, array_map('strtoupper', $profile->symbol_universe ?? []), true)) {
            return $this->reject('SYMBOL_NOT_IN_UNIVERSE', "Symbol {$symbol} not in profile universe.", $steps);
        }
        if ($timeframe !== '' && ! in_array($timeframe, $profile->timeframe_universe ?? [], true)) {
            return $this->reject('TIMEFRAME_NOT_IN_UNIVERSE', "Timeframe {$timeframe} not allowed.", $steps);
        }
        $matrixOk = false;
        foreach ($profile->strategy_matrix ?? [] as $row) {
            if (strcasecmp((string) ($row['symbol'] ?? ''), $symbol) === 0
                && ($timeframe === '' || (string) ($row['timeframe'] ?? '') === $timeframe)
                && ($strategyKey === '' || (string) ($row['strategy_key'] ?? '') === $strategyKey)
                && (string) ($row['strategy_version'] ?? 'v1') === $strategyVersion) {
                $matrixOk = true;
                break;
            }
        }
        if ($strategyKey !== '' && ! $matrixOk) {
            return $this->reject('STRATEGY_VERSION_LOCK', 'Strategy/version not locked in profile matrix.', $steps);
        }
        $steps[] = 'UNIVERSE_OK';

        // Locks
        if ($lock = $this->locks->blocksEntries($session)) {
            return $this->reject('ENTRY_LOCKED', $lock->lock_type->value.': '.$lock->reason, $steps);
        }
        if ($lock = $this->locks->blocksSymbol($session, $symbol)) {
            return $this->reject('SYMBOL_LOCKED', $lock->reason, $steps);
        }
        if ($session->auto_entry_paused || $session->entries_blocked || $session->safe_mode || $session->kill_switch) {
            return $this->reject('ENTRIES_PAUSED', 'Auto entry paused / safe / kill.', $steps);
        }
        $steps[] = 'LOCKS_OK';

        // Session / weekend
        $sessionPolicy = $profile->session_policy ?? [];
        if (! ($sessionPolicy['trade_weekends'] ?? false) && now()->isWeekend()) {
            return $this->reject('WEEKEND_CLOSED', 'Weekend trading disabled by profile.', $steps);
        }
        $steps[] = 'SESSION_OK';

        // Closed candle mode
        if ($profile->closed_candle_only && ($candidate['candle_state'] ?? 'CLOSED') !== 'CLOSED') {
            return $this->reject('OPEN_CANDLE_BLOCKED', 'Closed-candle mode only.', $steps);
        }
        $steps[] = 'CANDLE_OK';

        // TTL / freshness
        $ttl = (int) $profile->signal_ttl_seconds;
        $signalAt = isset($candidate['signal_at']) ? \Carbon\Carbon::parse($candidate['signal_at']) : now();
        if ($signalAt->lt(now()->subSeconds(max(1, $ttl)))) {
            return $this->reject('SIGNAL_TTL_EXPIRED', 'Signal exceeded TTL.', $steps, AutomationWorkflowState::Expired);
        }
        $steps[] = 'TTL_OK';

        // Confluence
        if ($confluence < $minConfluence) {
            return $this->reject('CONFLUENCE_TOO_LOW', "Confluence {$confluence} < {$minConfluence}.", $steps);
        }
        $steps[] = 'CONFLUENCE_OK';

        // Anomaly / spread / quality from candidate or intelligence
        $spreadPts = (float) ($candidate['spread_points'] ?? $intelligence?->spread['points'] ?? 0);
        $maxSpread = (float) (($profile->risk_overrides['max_spread'] ?? 50));
        if ($spreadPts > 0 && $spreadPts > $maxSpread) {
            return $this->reject('SPREAD_TOO_WIDE', "Spread {$spreadPts} > {$maxSpread}.", $steps);
        }
        $anomaly = $candidate['anomaly'] ?? $intelligence?->anomaly ?? null;
        if (is_array($anomaly) && (($anomaly['status'] ?? '') === 'ANOMALY' || ($anomaly['blocked'] ?? false) === true)) {
            return $this->reject('ANOMALY_BLOCK', (string) ($anomaly['reason'] ?? 'anomaly'), $steps);
        }
        $steps[] = 'QUALITY_OK';

        // Calendar fail-closed
        $calendar = $this->calendarPolicy->snapshot(false);
        $cal = $this->calendarPolicy->evaluate($profile, $symbol, $calendar);
        $steps[] = ['calendar' => $cal];
        if (! $cal['allowed']) {
            return $this->reject($cal['code'] ?? 'CALENDAR_BLOCK', $cal['detail'] ?? 'calendar', $steps);
        }

        // Intelligence required — AI NOT final authority; failure never silent bypass
        if ($intelRequired) {
            if ($intelligence === null) {
                if ($aiFailure === 'REJECT') {
                    return $this->reject('INTELLIGENCE_REQUIRED_MISSING', 'Intelligence required; missing → REJECT.', $steps);
                }
                if ($aiFailure === 'EXPIRE') {
                    return $this->reject('INTELLIGENCE_REQUIRED_MISSING', 'Intelligence required; missing → EXPIRE.', $steps, AutomationWorkflowState::Expired);
                }

                return [
                    'decision' => 'WAIT',
                    'code' => 'INTELLIGENCE_WAIT',
                    'detail' => 'Intelligence required; waiting — no silent bypass.',
                    'qualification' => ['steps' => $steps, 'ai_authority' => false],
                    'next_state' => AutomationWorkflowState::IntelligenceWait,
                ];
            }
            if (($intelligence->status ?? '') === 'FAILED' || ($intelligence->payload['ai_status'] ?? '') === 'FAILED') {
                if ($aiFailure === 'WAIT') {
                    return [
                        'decision' => 'WAIT',
                        'code' => 'INTELLIGENCE_AI_FAILED_WAIT',
                        'detail' => 'AI failure → WAIT (never bypass).',
                        'qualification' => ['steps' => $steps, 'ai_authority' => false],
                        'next_state' => AutomationWorkflowState::IntelligenceWait,
                    ];
                }
                if ($aiFailure === 'EXPIRE') {
                    return $this->reject('INTELLIGENCE_AI_FAILED', 'AI failure → EXPIRE.', $steps, AutomationWorkflowState::Expired);
                }

                return $this->reject('INTELLIGENCE_AI_FAILED', 'AI failure → REJECT.', $steps);
            }
            // Intelligence can advise block via rules
            $rulesFired = $intelligence->rules_fired ?? [];
            foreach ($rulesFired as $rule) {
                $code = is_array($rule) ? ($rule['code'] ?? '') : (string) $rule;
                if (in_array($code, ['MQ_BLOCK', 'SPREAD_WIDE', 'ANOMALY', 'CALENDAR_UNAVAILABLE', 'NEWS_UNAVAILABLE'], true)) {
                    return $this->reject('INTELLIGENCE_RULE_'.$code, 'Intelligence advised block (deterministic).', $steps);
                }
            }
            $steps[] = 'INTELLIGENCE_OK';
        } else {
            $steps[] = 'INTELLIGENCE_OPTIONAL_SKIPPED';
        }

        // Duplicate open / foreign positions: query actual positions
        $openOwned = Position::query()
            ->where('user_id', $session->user_id)
            ->where('status', PositionStatus::Open)
            ->whereHas('instrument', fn ($q) => $q->where('symbol', $symbol))
            ->count();
        $maxOpen = (int) $profile->max_open_positions;
        $totalOpen = Position::query()
            ->where('user_id', $session->user_id)
            ->where('status', PositionStatus::Open)
            ->count();
        if ($totalOpen >= $maxOpen) {
            return $this->reject('MAX_OPEN_POSITIONS', "Open {$totalOpen} >= {$maxOpen}.", $steps);
        }
        $dupPolicy = $profile->reentry_policy ?? [];
        if ($openOwned > 0 && ! ($dupPolicy['allow_same_direction_reentry'] ?? false)) {
            return $this->reject('DUPLICATE_POSITION_BLOCKED', 'Existing open position on symbol; conservative re-entry.', $steps);
        }
        $steps[] = 'POSITION_POLICY_OK';

        // No revenge / martingale
        if ($profile->allow_revenge_trading || $profile->allow_martingale) {
            return $this->reject('FORBIDDEN_POLICY', 'Revenge/martingale forbidden.', $steps);
        }
        $steps[] = 'NO_REVENGE_MARTINGALE';

        return [
            'decision' => 'QUALIFIED',
            'code' => null,
            'detail' => null,
            'qualification' => [
                'steps' => $steps,
                'ai_authority' => false,
                'symbol' => $symbol,
                'direction' => $direction,
                'strategy_key' => $strategyKey,
                'strategy_version' => $strategyVersion,
                'confluence' => $confluence,
                'engine' => 'AutomatedCandidateQualificationEngine/v1',
            ],
            'next_state' => AutomationWorkflowState::Qualified,
        ];
    }

    public function fingerprint(array $candidate): string
    {
        $parts = [
            strtoupper((string) ($candidate['symbol'] ?? '')),
            (string) ($candidate['timeframe'] ?? ''),
            strtoupper((string) ($candidate['direction'] ?? $candidate['side'] ?? '')),
            (string) ($candidate['strategy_key'] ?? $candidate['strategy'] ?? ''),
            (string) ($candidate['strategy_version'] ?? 'v1'),
            (string) ($candidate['candle_id'] ?? $candidate['closed_at'] ?? ''),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  list<mixed>  $steps
     * @return array{decision:string,code:?string,detail:?string,qualification:array<string,mixed>,next_state:AutomationWorkflowState}
     */
    private function reject(string $code, string $detail, array $steps, AutomationWorkflowState $state = AutomationWorkflowState::Rejected): array
    {
        return [
            'decision' => $state === AutomationWorkflowState::Expired ? 'EXPIRE' : 'REJECT',
            'code' => $code,
            'detail' => $detail,
            'qualification' => ['steps' => $steps, 'ai_authority' => false, 'rejected' => true],
            'next_state' => $state,
        ];
    }
}
