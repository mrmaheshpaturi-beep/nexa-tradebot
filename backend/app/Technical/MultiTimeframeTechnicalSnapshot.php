<?php

namespace App\Technical;

final class MultiTimeframeTechnicalSnapshot
{
    /**
     * @param  array<string, TechnicalSnapshot>  $frames
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $primaryTimeframe,
        public readonly array $frames,
        public readonly ?string $alignedBias,
        public readonly string $status,
    ) {}

    public function frame(string $timeframe): ?TechnicalSnapshot
    {
        return $this->frames[strtoupper($timeframe)] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'primary_timeframe' => $this->primaryTimeframe,
            'aligned_bias' => $this->alignedBias,
            'status' => $this->status,
            'frames' => collect($this->frames)->map(fn (TechnicalSnapshot $s) => $s->toArray())->all(),
        ];
    }
}
