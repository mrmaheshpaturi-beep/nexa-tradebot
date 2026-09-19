<?php

namespace App\TradeManagement;

/**
 * Immutable market + risk snapshot for one management cycle.
 */
final class ManagementContext
{
    /**
     * @param  array<string,mixed>  $quote
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $extras
     */
    public function __construct(
        public readonly array $quote,
        public readonly array $spec,
        public readonly array $risk,
        public readonly float $mid,
        public readonly float $bid,
        public readonly float $ask,
        public readonly bool $strategyInvalidated = false,
        public readonly bool $sessionClosing = false,
        public readonly bool $weekendImminent = false,
        public readonly bool $emergency = false,
        public readonly ?float $atr = null,
        public readonly ?float $structureStop = null,
        public readonly array $extras = [],
    ) {}

    public function mark(): string
    {
        return (string) ($this->quote['as_of'] ?? now()->toIso8601String());
    }
}
