<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class SupportResistanceReactionStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'sr_reaction'; }
    public function name(): string { return 'S/R Reaction'; }
    public function category(): string { return 'REVERSAL'; }
    public function description(): string { return 'Reaction near recent swing support or resistance.'; }
    public function evidenceFamily(): string { return 'SR'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $sr = $context->technical->supportResistance;
        $close = $context->lastClose();
        $atr = $context->atr();
        if ($close === null || $atr === null || empty($sr['support']) || empty($sr['resistance'])) {
            return StrategyEvaluation::idle($this->key(), 'NO_SR');
        }
        $nearSupport = abs($close - (float) $sr['support']) <= ($atr * 0.4);
        $nearResist = abs((float) $sr['resistance'] - $close) <= ($atr * 0.4);
        if ($nearSupport && ! $nearResist) {
            return $this->signal('BUY', 64, ['near' => 'support'], [
                $this->evidence('SR_SUPPORT', 'BUY', 1.0, 'Price reacting near support'),
            ], 'Support reaction', $context);
        }
        if ($nearResist && ! $nearSupport) {
            return $this->signal('SELL', 64, ['near' => 'resistance'], [
                $this->evidence('SR_RESISTANCE', 'SELL', 1.0, 'Price reacting near resistance'),
            ], 'Resistance reaction', $context);
        }

        return StrategyEvaluation::idle($this->key(), 'NOT_NEAR_SR');
    }
}
