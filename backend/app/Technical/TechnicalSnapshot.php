<?php

namespace App\Technical;

/**
 * Phase 7 thin adapter snapshot over Phase 6 IndicatorEngine + closed candles.
 * Full TechnicalAnalysisEngine may arrive from a later Phase 6 finalize; this contract is stable.
 */
final class TechnicalSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  array<string, mixed>  $indicators
     * @param  array<string, mixed>  $structure
     * @param  array<string, mixed>  $supportResistance
     * @param  array<string, mixed>  $quality
     * @param  array<string, mixed>  $gate
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly string $source,
        public readonly string $environment,
        public readonly string $status,
        public readonly ?float $lastClose,
        public readonly ?float $sma20,
        public readonly ?float $ema12,
        public readonly ?float $ema26,
        public readonly ?float $ema50,
        public readonly ?float $rsi14,
        public readonly ?float $macd,
        public readonly ?float $macdSignal,
        public readonly ?float $macdHist,
        public readonly ?float $atr14,
        public readonly ?float $bbUpper,
        public readonly ?float $bbMiddle,
        public readonly ?float $bbLower,
        public readonly ?float $atr,
        public readonly array $candles,
        public readonly array $indicators,
        public readonly array $structure,
        public readonly array $supportResistance,
        public readonly array $quality,
        public readonly array $gate,
        public readonly string $candleCloseKey,
        public readonly bool $adapter = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'source' => $this->source,
            'environment' => $this->environment,
            'status' => $this->status,
            'last_close' => $this->lastClose,
            'sma20' => $this->sma20,
            'ema12' => $this->ema12,
            'ema26' => $this->ema26,
            'ema50' => $this->ema50,
            'rsi14' => $this->rsi14,
            'macd' => $this->macd,
            'macd_signal' => $this->macdSignal,
            'macd_hist' => $this->macdHist,
            'atr14' => $this->atr14,
            'bb_upper' => $this->bbUpper,
            'bb_middle' => $this->bbMiddle,
            'bb_lower' => $this->bbLower,
            'structure' => $this->structure,
            'support_resistance' => $this->supportResistance,
            'quality' => $this->quality,
            'gate' => $this->gate,
            'candle_close_key' => $this->candleCloseKey,
            'adapter' => $this->adapter,
            'indicator_status' => collect($this->indicators)->mapWithKeys(
                fn ($row, $key) => [$key => $row['status'] ?? null]
            )->all(),
        ];
    }
}
