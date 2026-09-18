<?php

namespace App\Strategies;

use App\Contracts\TradingStrategyPlugin;

abstract class AbstractStrategyPlugin implements TradingStrategyPlugin
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function category(): string;

    abstract public function description(): string;

    abstract public function evidenceFamily(): string;

    public function defaultParameters(): array
    {
        return [
            'atr_stop_mult' => 1.5,
            'atr_tp1_mult' => 2.0,
            'atr_tp2_mult' => 3.0,
            'min_raw_score' => 55,
        ];
    }

    /**
     * @param  list<array{code:string,family:string,direction:?string,weight:float,detail:string}>  $evidence
     * @param  array<string, float|int|string|bool|null>  $breakdown
     */
    protected function signal(
        string $direction,
        float $score,
        array $breakdown,
        array $evidence,
        string $reason,
        StrategyContext $context,
        ?string $regime = null,
        array $metadata = [],
    ): StrategyEvaluation {
        $entry = $context->lastClose();
        $atr = $context->atr() ?? 0.0;
        $params = array_merge($this->defaultParameters(), $context->parameters);
        $stopMult = (float) ($params['atr_stop_mult'] ?? 1.5);
        $tp1Mult = (float) ($params['atr_tp1_mult'] ?? 2.0);
        $tp2Mult = (float) ($params['atr_tp2_mult'] ?? 3.0);
        $stop = null;
        $tp1 = null;
        $tp2 = null;
        if ($entry !== null && $atr > 0) {
            if ($direction === 'BUY') {
                $stop = $entry - ($atr * $stopMult);
                $tp1 = $entry + ($atr * $tp1Mult);
                $tp2 = $entry + ($atr * $tp2Mult);
            } else {
                $stop = $entry + ($atr * $stopMult);
                $tp1 = $entry - ($atr * $tp1Mult);
                $tp2 = $entry - ($atr * $tp2Mult);
            }
        }

        return new StrategyEvaluation(
            pluginKey: $this->key(),
            status: 'SIGNAL',
            direction: $direction,
            rawScore: max(0, min(100, $score)),
            scoreBreakdown: $breakdown,
            evidence: $evidence,
            reason: $reason,
            entry: $entry,
            stopLoss: $stop,
            takeProfit1: $tp1,
            takeProfit2: $tp2,
            marketRegime: $regime ?? ($context->technical->structure['bias'] ?? null),
            metadata: $metadata,
        );
    }

    protected function evidence(string $code, string $direction, float $weight, string $detail): array
    {
        return [
            'code' => $code,
            'family' => $this->evidenceFamily(),
            'direction' => $direction,
            'weight' => $weight,
            'detail' => $detail,
        ];
    }

    protected function guardReady(StrategyContext $context): ?StrategyEvaluation
    {
        if ($context->technical->status === 'REFUSED') {
            return StrategyEvaluation::blocked($this->key(), 'TECHNICAL_SNAPSHOT_REFUSED');
        }
        if ($context->lastClose() === null) {
            return StrategyEvaluation::blocked($this->key(), 'NO_LAST_CLOSE');
        }

        return null;
    }
}
