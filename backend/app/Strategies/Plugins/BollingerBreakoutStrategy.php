<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class BollingerBreakoutStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'bollinger_breakout'; }
    public function name(): string { return 'Bollinger Breakout'; }
    public function category(): string { return 'BREAKOUT'; }
    public function description(): string { return 'Band expansion breakout with ATR confirmation.'; }
    public function evidenceFamily(): string { return 'VOLATILITY_BBANDS'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $t = $context->technical;
        $candles = $t->candles;
        if ($t->bbUpper === null || $t->bbLower === null || $t->lastClose === null || count($candles) < 25 || $t->atr === null) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_DATA');
        }
        $prev = array_slice($candles, -21, 20);
        $prevRanges = array_map(fn ($c) => (float) $c['high'] - (float) $c['low'], $prev);
        $avgRange = array_sum($prevRanges) / max(count($prevRanges), 1);
        $lastRange = (float) $candles[count($candles) - 1]['high'] - (float) $candles[count($candles) - 1]['low'];
        $expanding = $lastRange > ($avgRange * 1.25);
        $direction = null;
        if ($expanding && $t->lastClose > $t->bbUpper) {
            $direction = 'BUY';
        } elseif ($expanding && $t->lastClose < $t->bbLower) {
            $direction = 'SELL';
        }
        if ($direction === null) {
            return StrategyEvaluation::idle($this->key(), 'NO_BAND_BREAKOUT');
        }

        return $this->signal($direction, 68, ['range_expansion' => $lastRange / max($avgRange, 1e-9)], [
            $this->evidence('BB_BREAKOUT', $direction, 1.0, 'Volatility expansion through Bollinger band'),
        ], 'Bollinger breakout', $context, 'TREND');
    }
}
