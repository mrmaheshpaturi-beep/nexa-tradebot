<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class MtfTrendStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'mtf_trend'; }
    public function name(): string { return 'MTF Trend'; }
    public function category(): string { return 'TREND'; }
    public function description(): string { return 'Multi-timeframe trend alignment confirmation.'; }
    public function evidenceFamily(): string { return 'MTF'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $bias = $context->mtf->alignedBias;
        if ($bias === 'BULLISH') {
            return $this->signal('BUY', 72, ['mtf_bias' => $bias], [
                $this->evidence('MTF_ALIGN', 'BUY', 1.0, 'Higher timeframes agree bullish'),
            ], 'Multi-timeframe bullish alignment', $context, 'TREND');
        }
        if ($bias === 'BEARISH') {
            return $this->signal('SELL', 72, ['mtf_bias' => $bias], [
                $this->evidence('MTF_ALIGN', 'SELL', 1.0, 'Higher timeframes agree bearish'),
            ], 'Multi-timeframe bearish alignment', $context, 'TREND');
        }

        return StrategyEvaluation::idle($this->key(), 'MTF_MIXED', ['aligned_bias' => $bias]);
    }
}
