<?php

namespace App\Strategies;

use App\Models\TradingStrategy;
use App\Technical\MultiTimeframeTechnicalSnapshot;
use App\Technical\TechnicalSnapshot;

/**
 * Immutable evaluation inputs. Closed candles only — no look-ahead.
 */
final class StrategyContext
{
    /**
     * @param  array<string, mixed>  $marketSnapshot
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $allowedSessions
     */
    public function __construct(
        public readonly TradingStrategy $strategy,
        public readonly string $pluginKey,
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly array $marketSnapshot,
        public readonly TechnicalSnapshot $technical,
        public readonly MultiTimeframeTechnicalSnapshot $mtf,
        public readonly array $parameters,
        public readonly array $allowedSessions,
        public readonly string $prefer,
        public readonly string $candleCloseKey,
        public readonly int $configurationVersion,
    ) {}

    public function lastClose(): ?float
    {
        return $this->technical->lastClose;
    }

    public function atr(): ?float
    {
        return $this->technical->atr;
    }
}
