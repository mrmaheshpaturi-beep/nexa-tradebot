<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class VolatilityExpansionStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'volatility_expansion'; }
    public function name(): string { return 'Volatility Expansion'; }
    public function category(): string { return 'BREAKOUT'; }
    public function description(): string { return 'ATR expansion with directional close confirmation.'; }
    public function evidenceFamily(): string { return 'VOLATILITY_ATR'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $candles = $context->technical->candles;
        $atr = $context->atr();
        if (count($candles) < 30 || $atr === null) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_DATA');
        }
        $ranges = array_map(fn ($c) => (float) $c['high'] - (float) $c['low'], array_slice($candles, -30, 25));
        $avg = array_sum($ranges) / max(count($ranges), 1);
        $last = (float) $candles[count($candles) - 1]['high'] - (float) $candles[count($candles) - 1]['low'];
        if ($last < $avg * 1.4 || $atr < $avg) {
            return StrategyEvaluation::idle($this->key(), 'NO_EXPANSION', ['last' => $last, 'avg' => $avg]);
        }
        $open = (float) $candles[count($candles) - 1]['open'];
        $close = (float) $candles[count($candles) - 1]['close'];
        $direction = $close >= $open ? 'BUY' : 'SELL';

        return $this->signal($direction, 67, ['expansion_ratio' => $last / max($avg, 1e-9)], [
            $this->evidence('ATR_EXPANSION', $direction, 1.0, 'Range expansion with directional close'),
        ], 'Volatility expansion continuation', $context, 'VOLATILE');
    }
}
