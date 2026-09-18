<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class MarketStructureStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'market_structure'; }
    public function name(): string { return 'Market Structure'; }
    public function category(): string { return 'TREND'; }
    public function description(): string { return 'Higher-high / higher-low or lower-high / lower-low structure.'; }
    public function evidenceFamily(): string { return 'STRUCTURE'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $s = $context->technical->structure;
        $bias = $s['bias'] ?? 'UNKNOWN';
        if ($bias === 'BULLISH' && ($s['hh'] ?? false) && ($s['hl'] ?? false)) {
            return $this->signal('BUY', 66, ['structure' => $bias], [
                $this->evidence('STRUCTURE_HH_HL', 'BUY', 1.0, 'Higher highs and higher lows'),
            ], 'Bullish market structure', $context, 'TREND');
        }
        if ($bias === 'BEARISH' && ($s['lh'] ?? false) && ($s['ll'] ?? false)) {
            return $this->signal('SELL', 66, ['structure' => $bias], [
                $this->evidence('STRUCTURE_LH_LL', 'SELL', 1.0, 'Lower highs and lower lows'),
            ], 'Bearish market structure', $context, 'TREND');
        }

        return StrategyEvaluation::idle($this->key(), 'NO_CLEAR_STRUCTURE', $s);
    }
}
