<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class RsiMomentumStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'rsi_momentum'; }
    public function name(): string { return 'RSI Momentum'; }
    public function category(): string { return 'MOMENTUM'; }
    public function description(): string { return 'RSI momentum exits from oversold/overbought zones.'; }
    public function evidenceFamily(): string { return 'MOMENTUM_RSI'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $rsi = $context->technical->rsi14;
        if ($rsi === null) {
            return StrategyEvaluation::idle($this->key(), 'NO_RSI');
        }
        $direction = null;
        $score = 0.0;
        if ($rsi <= 35) {
            $direction = 'BUY';
            $score = 55 + (35 - $rsi);
        } elseif ($rsi >= 65) {
            $direction = 'SELL';
            $score = 55 + ($rsi - 65);
        }
        if ($direction === null) {
            return StrategyEvaluation::idle($this->key(), 'RSI_NEUTRAL', ['rsi' => $rsi]);
        }
        if ($context->technical->ema12 !== null && $context->technical->ema26 !== null) {
            $aligned = ($direction === 'BUY' && $context->technical->ema12 >= $context->technical->ema26)
                || ($direction === 'SELL' && $context->technical->ema12 <= $context->technical->ema26);
            if ($aligned) {
                $score += 10;
            }
        }

        return $this->signal($direction, min(100, $score), ['rsi' => $rsi, 'base' => 55], [
            $this->evidence('RSI_MOMENTUM', $direction, 1.0, "RSI={$rsi}"),
        ], 'RSI momentum zone exit', $context);
    }
}
