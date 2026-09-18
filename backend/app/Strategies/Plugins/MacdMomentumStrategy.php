<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class MacdMomentumStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'macd_momentum'; }
    public function name(): string { return 'MACD Momentum'; }
    public function category(): string { return 'MOMENTUM'; }
    public function description(): string { return 'MACD line vs signal momentum confirmation.'; }
    public function evidenceFamily(): string { return 'MOMENTUM_MACD'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $macd = $context->technical->macd;
        $signal = $context->technical->macdSignal;
        $hist = $context->technical->macdHist;
        if ($macd === null || $signal === null) {
            return StrategyEvaluation::idle($this->key(), 'NO_MACD');
        }
        $bull = $macd > $signal && ($hist === null || $hist > 0);
        $bear = $macd < $signal && ($hist === null || $hist < 0);
        if (! $bull && ! $bear) {
            return StrategyEvaluation::idle($this->key(), 'MACD_FLAT');
        }
        $direction = $bull ? 'BUY' : 'SELL';
        $score = 60 + min(20, abs(($hist ?? ($macd - $signal))) * 5000);

        return $this->signal($direction, $score, ['macd' => $macd, 'signal' => $signal, 'hist' => $hist], [
            $this->evidence('MACD_MOMENTUM', $direction, 1.0, 'MACD crossed signal with histogram support'),
        ], 'MACD momentum confirmation', $context);
    }
}
