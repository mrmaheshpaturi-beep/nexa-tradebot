<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class EmaPullbackStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'ema_pullback'; }
    public function name(): string { return 'EMA Pullback'; }
    public function category(): string { return 'TREND'; }
    public function description(): string { return 'Pullback toward EMA in an established trend.'; }
    public function evidenceFamily(): string { return 'TREND_EMA'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $t = $context->technical;
        if ($t->ema12 === null || $t->ema26 === null || $t->ema50 === null || $t->lastClose === null || $t->atr === null) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_DATA');
        }
        $upTrend = $t->ema12 > $t->ema26 && $t->ema26 > $t->ema50;
        $downTrend = $t->ema12 < $t->ema26 && $t->ema26 < $t->ema50;
        if (! $upTrend && ! $downTrend) {
            return StrategyEvaluation::idle($this->key(), 'NO_TREND_STACK');
        }
        $nearEma = abs($t->lastClose - $t->ema12) <= ($t->atr * 0.35);
        if (! $nearEma) {
            return StrategyEvaluation::idle($this->key(), 'NOT_NEAR_EMA', ['distance' => abs($t->lastClose - $t->ema12)]);
        }
        $direction = $upTrend ? 'BUY' : 'SELL';
        $score = 62 + min(15, (1 - abs($t->lastClose - $t->ema12) / max($t->atr, 1e-9)) * 15);

        return $this->signal($direction, $score, ['base' => 62, 'pullback_proximity' => round($score - 62, 2)], [
            $this->evidence('EMA_PULLBACK', $direction, 1.0, 'Price pulled back to EMA12 in trend'),
        ], 'EMA pullback continuation', $context);
    }
}
