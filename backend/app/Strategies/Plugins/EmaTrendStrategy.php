<?php

namespace App\Strategies\Plugins;

use App\Strategies\AbstractStrategyPlugin;
use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

class EmaTrendStrategy extends AbstractStrategyPlugin
{
    public function key(): string { return 'ema_trend'; }
    public function name(): string { return 'EMA Trend'; }
    public function category(): string { return 'TREND'; }
    public function description(): string { return 'EMA12/EMA26 trend alignment with price confirmation.'; }
    public function evidenceFamily(): string { return 'TREND_EMA'; }

    public function defaultParameters(): array
    {
        return array_merge(parent::defaultParameters(), ['require_ema50' => true]);
    }

    public function evaluate(StrategyContext $context): StrategyEvaluation
    {
        if ($blocked = $this->guardReady($context)) {
            return $blocked;
        }
        $t = $context->technical;
        if ($t->ema12 === null || $t->ema26 === null || $t->lastClose === null) {
            return StrategyEvaluation::idle($this->key(), 'INSUFFICIENT_EMA');
        }
        $bull = $t->ema12 > $t->ema26 && $t->lastClose > $t->ema12;
        $bear = $t->ema12 < $t->ema26 && $t->lastClose < $t->ema12;
        if (! $bull && ! $bear) {
            return StrategyEvaluation::idle($this->key(), 'NO_EMA_TREND', ['ema12' => $t->ema12, 'ema26' => $t->ema26]);
        }
        $direction = $bull ? 'BUY' : 'SELL';
        $score = 58.0;
        $breakdown = ['base' => 50, 'ema_cross' => 8];
        if (($context->parameters['require_ema50'] ?? true) && $t->ema50 !== null) {
            if (($bull && $t->lastClose > $t->ema50) || ($bear && $t->lastClose < $t->ema50)) {
                $score += 12;
                $breakdown['ema50_align'] = 12;
            }
        }
        if (($t->structure['bias'] ?? '') === ($bull ? 'BULLISH' : 'BEARISH')) {
            $score += 8;
            $breakdown['structure'] = 8;
        }

        return $this->signal($direction, $score, $breakdown, [
            $this->evidence('EMA_TREND', $direction, 1.0, 'EMA12/26 aligned with price'),
        ], 'EMA trend continuation setup', $context);
    }
}
