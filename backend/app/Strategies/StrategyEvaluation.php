<?php

namespace App\Strategies;

/**
 * Strategy plugin output. Scores are transparent confluence inputs (0–100),
 * NOT win probability. Never claim guaranteed outcomes.
 */
final class StrategyEvaluation
{
    /**
     * @param  list<array{code:string,family:string,direction:?string,weight:float,detail:string}>  $evidence
     * @param  array<string, float|int|string|bool|null>  $scoreBreakdown
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $pluginKey,
        public readonly string $status,
        public readonly ?string $direction,
        public readonly float $rawScore,
        public readonly array $scoreBreakdown,
        public readonly array $evidence,
        public readonly string $reason,
        public readonly ?float $entry = null,
        public readonly ?float $stopLoss = null,
        public readonly ?float $takeProfit1 = null,
        public readonly ?float $takeProfit2 = null,
        public readonly ?string $marketRegime = null,
        public readonly array $metadata = [],
    ) {}

    public function isActionable(): bool
    {
        return $this->status === 'SIGNAL'
            && in_array($this->direction, ['BUY', 'SELL'], true)
            && $this->rawScore > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plugin_key' => $this->pluginKey,
            'status' => $this->status,
            'direction' => $this->direction,
            'raw_score' => round($this->rawScore, 3),
            'score_breakdown' => $this->scoreBreakdown,
            'evidence' => $this->evidence,
            'reason' => $this->reason,
            'entry' => $this->entry,
            'stop_loss' => $this->stopLoss,
            'take_profit_1' => $this->takeProfit1,
            'take_profit_2' => $this->takeProfit2,
            'market_regime' => $this->marketRegime,
            'metadata' => $this->metadata,
            'disclaimer' => 'Score is a transparent confluence measure (0–100), not a win probability or guarantee.',
        ];
    }

    public static function idle(string $pluginKey, string $reason, array $breakdown = []): self
    {
        return new self(
            pluginKey: $pluginKey,
            status: 'IDLE',
            direction: 'NEUTRAL',
            rawScore: 0,
            scoreBreakdown: $breakdown,
            evidence: [],
            reason: $reason,
        );
    }

    public static function blocked(string $pluginKey, string $reason, array $breakdown = []): self
    {
        return new self(
            pluginKey: $pluginKey,
            status: 'BLOCKED',
            direction: 'NEUTRAL',
            rawScore: 0,
            scoreBreakdown: $breakdown,
            evidence: [],
            reason: $reason,
        );
    }
}
