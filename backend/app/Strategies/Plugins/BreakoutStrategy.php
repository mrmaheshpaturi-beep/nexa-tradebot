<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class BreakoutStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'breakout'; }
    public function name(): string { return 'Breakout'; }
    public function category(): string { return 'BREAKOUT'; }
    public function description(): string { return 'Close beyond recent swing range with ATR buffer.'; }
    public function evidenceFamily(): string { return 'BREAKOUT'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $candles = $context->technical->candles;
        $atr = $context->atr();
        $close = $context->lastClose();
        if (count($candles) < 25 || $atr === null || $close === null) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_DATA');
        }
        $window = array_slice($candles, -21, 20);
        $high = max(array_map(fn ($c) => (float) $c['high'], $window));
        $low = min(array_map(fn ($c) => (float) $c['low'], $window));
        if ($close > $high + ($atr * 0.1)) {
            return $this->signal('BUY', 70, ['break_level' => $high], [
                $this->evidence('RANGE_BREAKOUT', 'BUY', 1.0, 'Close above prior swing high'),
            ], 'Upside range breakout', $context, 'TREND');
        }
        if ($close < $low - ($atr * 0.1)) {
            return $this->signal('SELL', 70, ['break_level' => $low], [
                $this->evidence('RANGE_BREAKOUT', 'SELL', 1.0, 'Close below prior swing low'),
            ], 'Downside range breakout', $context, 'TREND');
        }

        return StrategyEvaluation::idle($this->key(), 'INSIDE_RANGE', ['high' => $high, 'low' => $low]);
    }
}
