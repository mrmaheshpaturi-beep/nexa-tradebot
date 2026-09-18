<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class BollingerMeanReversionStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'bollinger_mean_reversion'; }
    public function name(): string { return 'Bollinger Mean Reversion'; }
    public function category(): string { return 'REVERSAL'; }
    public function description(): string { return 'Fade touches of Bollinger outer bands toward the mid.'; }
    public function evidenceFamily(): string { return 'VOLATILITY_BBANDS'; }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $t = $context->technical;
        if ($t->bbUpper === null || $t->bbLower === null || $t->bbMiddle === null || $t->lastClose === null) {
            return StrategyEvaluation::idle($this->key(), 'NO_BBANDS');
        }
        $direction = null;
        if ($t->lastClose <= $t->bbLower) {
            $direction = 'BUY';
        } elseif ($t->lastClose >= $t->bbUpper) {
            $direction = 'SELL';
        }
        if ($direction === null) {
            return StrategyEvaluation::idle($this->key(), 'INSIDE_BANDS');
        }
        $width = max($t->bbUpper - $t->bbLower, 1e-9);
        $ext = $direction === 'BUY'
            ? ($t->bbLower - $t->lastClose) / $width
            : ($t->lastClose - $t->bbUpper) / $width;
        $score = 58 + min(20, abs($ext) * 100);

        return $this->signal($direction, $score, ['band_extension' => $ext], [
            $this->evidence('BB_MEAN_REVERSION', $direction, 1.0, 'Price outside Bollinger band'),
        ], 'Bollinger mean reversion', $context, 'RANGE');
    }
}
