<?php

namespace App\Contracts;

use App\Strategies\StrategyContext;
use App\Strategies\StrategyEvaluation;

/**
 * Built-in strategy plugin contract. Arbitrary code upload is not supported.
 */
interface TradingStrategyPlugin
{
    public function key(): string;

    public function name(): string;

    public function category(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function defaultParameters(): array;

    /**
     * Evidence family used by ConfluenceEngine to avoid double-counting.
     * Examples: TREND_EMA, MOMENTUM_RSI, VOLATILITY_BBANDS, STRUCTURE, SR, BREAKOUT, MTF, ADX
     */
    public function evidenceFamily(): string;

    public function evaluate(StrategyContext $context): StrategyEvaluation;
}
