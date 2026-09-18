<?php

namespace App\Services;

use App\Models\StrategyEvaluationRecord;
use App\Models\StrategyPerformanceStat;
use App\Models\Signal;
use App\Models\TradingStrategy;
use App\Enums\SignalStatus;
use Illuminate\Support\Facades\DB;

/**
 * Real strategy stats only — never invents win rates.
 */
class StrategyPerformanceService
{
    public function summarize(TradingStrategy $strategy): array
    {
        $signals = Signal::query()->where('trading_strategy_id', $strategy->id);
        $generated = (clone $signals)->count();
        $expired = (clone $signals)->where('status', SignalStatus::Expired->value)->count();
        $consumed = (clone $signals)->where('status', SignalStatus::Consumed->value)->count();
        $avgScore = (clone $signals)->whereNotNull('score')->avg('score');
        $byDirection = Signal::query()
            ->where('trading_strategy_id', $strategy->id)
            ->select('direction', DB::raw('count(*) as total'))
            ->groupBy('direction')
            ->pluck('total', 'direction')
            ->all();
        $evaluations = StrategyEvaluationRecord::query()->where('trading_strategy_id', $strategy->id)->count();

        $payload = [
            'trading_strategy_id' => $strategy->id,
            'signals_generated' => $generated,
            'signals_expired' => $expired,
            'signals_consumed' => $consumed,
            'evaluations' => $evaluations,
            'avg_score' => $avgScore !== null ? round((float) $avgScore, 3) : null,
            'by_direction' => $byDirection,
            'win_rate' => null,
            'win_rate_note' => 'Win rate is not reported until closed simulated outcomes exist. No fabricated win rates.',
            'computed_at' => now('UTC')->toIso8601String(),
        ];

        StrategyPerformanceStat::query()->updateOrCreate(
            [
                'trading_strategy_id' => $strategy->id,
                'symbol' => null,
                'timeframe' => null,
            ],
            [
                'signals_generated' => $generated,
                'signals_expired' => $expired,
                'signals_consumed' => $consumed,
                'evaluations' => $evaluations,
                'avg_score' => $payload['avg_score'],
                'by_direction' => $byDirection,
                'computed_at' => now('UTC'),
            ],
        );

        return $payload;
    }
}
