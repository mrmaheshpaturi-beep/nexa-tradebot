<?php

namespace App\Services;

use App\Enums\SignalDirection;
use App\Enums\SignalSource;
use App\Enums\SignalStatus;
use App\Enums\TradingEnvironment;
use App\Models\Signal;
use App\Models\StrategyEvaluationRecord;
use App\Models\TradingInstrument;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Strategies\StrategyEvaluation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates and manages Signal lifecycle. Never sends broker orders.
 */
class SignalEngineService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $confluence
     * @param  list<array<string, mixed>>  $evaluations
     * @param  array<string, mixed>  $gate
     */
    public function maybeCreate(
        User $user,
        TradingStrategy $strategy,
        string $symbol,
        string $timeframe,
        string $candleCloseKey,
        StrategyEvaluation $primary,
        array $confluence,
        array $evaluations,
        array $gate,
        int $configurationVersion,
        bool $persistEvaluation = true,
    ): array {
        $fingerprint = $this->fingerprint($strategy->id, $symbol, $timeframe, $primary->direction ?? 'NEUTRAL', $candleCloseKey, $configurationVersion);

        $evaluation = null;
        if ($persistEvaluation) {
            $evaluation = StrategyEvaluationRecord::query()->updateOrCreate(
                [
                    'trading_strategy_id' => $strategy->id,
                    'symbol' => strtoupper($symbol),
                    'timeframe' => strtoupper($timeframe),
                    'candle_close_key' => $candleCloseKey,
                    'plugin_key' => $primary->pluginKey,
                ],
                [
                    'user_id' => $user->id,
                    'configuration_version' => $configurationVersion,
                    'status' => $primary->status,
                    'direction' => $primary->direction,
                    'raw_score' => $primary->rawScore,
                    'confluence_score' => $confluence['score'] ?? null,
                    'score_breakdown' => $primary->scoreBreakdown,
                    'evidence' => $primary->evidence,
                    'confluence' => $confluence,
                    'reason' => $primary->reason,
                    'gate' => $gate,
                    'metadata' => [
                        'evaluations' => $evaluations,
                        'disclaimer' => 'Score is not a win probability.',
                    ],
                ],
            );
        }

        if (! $primary->isActionable()) {
            return ['signal' => null, 'evaluation' => $evaluation, 'skipped' => 'NOT_ACTIONABLE'];
        }

        if (! ($gate['allowed'] ?? false)) {
            return ['signal' => null, 'evaluation' => $evaluation, 'skipped' => $gate['reason'] ?? 'GATE_BLOCKED'];
        }

        $minScore = $this->minimumScoreThreshold($strategy);
        $finalScore = (float) ($confluence['score'] ?? $primary->rawScore);
        if ($finalScore < $minScore) {
            return ['signal' => null, 'evaluation' => $evaluation, 'skipped' => 'BELOW_MIN_SCORE', 'min_score' => $minScore, 'score' => $finalScore];
        }

        $cooldownMinutes = (int) ($strategy->parameters['cooldown_minutes'] ?? 30);
        $duplicate = Signal::query()
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', [SignalStatus::Generated->value, SignalStatus::Valid->value])
            ->first();
        if ($duplicate) {
            return ['signal' => $duplicate, 'evaluation' => $evaluation, 'skipped' => 'DUPLICATE_FINGERPRINT', 'replayed' => true];
        }

        $recent = Signal::query()
            ->where('trading_strategy_id', $strategy->id)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('direction', $primary->direction)
            ->where('generated_at', '>=', now('UTC')->subMinutes($cooldownMinutes))
            ->whereIn('status', [SignalStatus::Generated->value, SignalStatus::Valid->value])
            ->first();
        if ($recent) {
            return ['signal' => $recent, 'evaluation' => $evaluation, 'skipped' => 'COOLDOWN', 'replayed' => true];
        }

        $instrument = TradingInstrument::query()->where('symbol', strtoupper($symbol))->first();
        $expiryMinutes = (int) ($strategy->parameters['expiry_minutes'] ?? 120);

        $signal = DB::transaction(function () use ($user, $strategy, $symbol, $timeframe, $primary, $confluence, $evaluations, $fingerprint, $instrument, $expiryMinutes, $configurationVersion, $finalScore, $candleCloseKey) {
            return Signal::query()->create([
                'user_id' => $user->id,
                'trading_strategy_id' => $strategy->id,
                'trading_instrument_id' => $instrument?->id,
                'symbol' => strtoupper($symbol),
                'direction' => SignalDirection::from($primary->direction),
                'timeframe' => strtoupper($timeframe),
                'score' => round($finalScore, 3),
                'entry_price' => $primary->entry,
                'entry_reference' => $primary->entry,
                'stop_loss' => $primary->stopLoss,
                'take_profit_1' => $primary->takeProfit1,
                'take_profit_1_reference' => $primary->takeProfit1,
                'take_profit_2' => $primary->takeProfit2,
                'take_profit_2_reference' => $primary->takeProfit2,
                'risk_reward' => $this->riskReward($primary),
                'market_regime' => $primary->marketRegime,
                'status' => SignalStatus::Generated,
                'source' => SignalSource::Strategy,
                'explanation' => $this->explanation($primary, $confluence),
                'environment' => TradingEnvironment::Simulation,
                'generated_at' => now('UTC'),
                'expires_at' => now('UTC')->addMinutes($expiryMinutes),
                'fingerprint' => $fingerprint,
                'plugin_key' => $primary->pluginKey,
                'configuration_version' => $configurationVersion,
                'confluence_score' => $confluence['score'] ?? null,
                'score_breakdown' => [
                    'primary' => $primary->scoreBreakdown,
                    'confluence' => $confluence['breakdown'] ?? [],
                    'disclaimer' => 'Score is a transparent confluence measure (0–100), not a win probability or guarantee.',
                ],
                'confluence' => $confluence,
                'candle_close_key' => $candleCloseKey,
                'auto_simulation' => false,
                'metadata' => [
                    'evaluations' => $evaluations,
                    'evidence_families' => $confluence['families'] ?? [],
                    'conflicts' => $confluence['conflicts'] ?? [],
                    'phase' => 7,
                ],
            ]);
        });

        return ['signal' => $signal, 'evaluation' => $evaluation, 'skipped' => null, 'created' => true];
    }

    public function expireDue(): int
    {
        return Signal::query()
            ->whereIn('status', [SignalStatus::Generated->value, SignalStatus::Valid->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now('UTC'))
            ->update(['status' => SignalStatus::Expired->value]);
    }

    public function invalidate(Signal $signal, string $reason): Signal
    {
        $signal->update([
            'status' => SignalStatus::Cancelled,
            'invalidation_reason' => $reason,
            'metadata' => array_merge($signal->metadata ?? [], ['invalidated_at' => now('UTC')->toIso8601String()]),
        ]);

        return $signal->refresh();
    }

    public function fingerprint(
        int $strategyId,
        string $symbol,
        string $timeframe,
        string $direction,
        string $candleCloseKey,
        int $configurationVersion,
    ): string {
        return hash('sha256', implode('|', [
            $strategyId,
            strtoupper($symbol),
            strtoupper($timeframe),
            strtoupper($direction),
            $candleCloseKey,
            $configurationVersion,
        ]));
    }

    private function minimumScoreThreshold(TradingStrategy $strategy): float
    {
        $min = (float) $strategy->minimum_signal_score;
        // Legacy rows may store 0–1; Phase 7 scores are 0–100.
        if ($min > 0 && $min <= 1.0) {
            $min *= 100;
        }

        return $min;
    }

    private function riskReward(StrategyEvaluation $primary): ?float
    {
        if ($primary->entry === null || $primary->stopLoss === null || $primary->takeProfit1 === null) {
            return null;
        }
        $risk = abs($primary->entry - $primary->stopLoss);
        if ($risk <= 0) {
            return null;
        }

        return round(abs($primary->takeProfit1 - $primary->entry) / $risk, 4);
    }

    /** @param  array<string, mixed>  $confluence */
    private function explanation(StrategyEvaluation $primary, array $confluence): string
    {
        $families = implode(', ', array_map(fn ($f) => $f['family'] ?? '', $confluence['families'] ?? []));
        $score = $confluence['score'] ?? $primary->rawScore;

        return sprintf(
            '%s (%s). Confluence score %.1f/100 from families [%s]. This score is NOT a win probability and does not guarantee outcomes.',
            $primary->reason,
            $primary->pluginKey,
            $score,
            $families !== '' ? $families : 'none',
        );
    }
}
