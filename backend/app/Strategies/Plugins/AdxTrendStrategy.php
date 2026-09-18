<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

/** ADX-like trend strength from ATR-normalized directional movement (adapter; no native ADX indicator). */
class AdxTrendStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'adx_trend'; }
    public function name(): string { return 'ADX Trend'; }
    public function category(): string { return 'TREND'; }
    public function description(): string { return 'Directional strength proxy using ATR-normalized swing structure.'; }
    public function evidenceFamily(): string { return 'ADX'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $candles = $context->technical->candles;
        $atr = $context->atr();
        if (count($candles) < 30 || $atr === null || $atr <= 0) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_DATA');
        }
        $slice = array_slice($candles, -15);
        $up = 0.0;
        $down = 0.0;
        for ($i = 1; $i < count($slice); $i++) {
            $delta = (float) $slice[$i]['close'] - (float) $slice[$i - 1]['close'];
            if ($delta > 0) {
                $up += $delta;
            } else {
                $down += abs($delta);
            }
        }
        $strength = abs($up - $down) / $atr;
        if ($strength < 1.2) {
            return StrategyEvaluation::idle($this->key(), 'WEAK_DIRECTIONAL_STRENGTH', ['strength' => $strength]);
        }
        $direction = $up > $down ? 'BUY' : 'SELL';
        $score = 55 + min(30, $strength * 8);

        return $this->signal($direction, $score, ['directional_strength' => $strength], [
            $this->evidence('ADX_PROXY', $direction, 1.0, 'ATR-normalized directional strength'),
        ], 'ADX-proxy trend continuation', $context, 'TREND');
    }
}
