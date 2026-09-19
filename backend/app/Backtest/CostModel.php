<?php

namespace App\Backtest;

/**
 * Explicit cost model for backtests. Deterministic given same inputs.
 *
 * @phpstan-type CostConfig array{
 *   spread_points?: float,
 *   point_value?: float,
 *   commission_per_lot?: float,
 *   slippage_points?: float,
 *   swap_long_per_lot_per_day?: float,
 *   swap_short_per_lot_per_day?: float
 * }
 */
final class CostModel
{
    /**
     * @param  CostConfig  $config
     */
    public function __construct(private readonly array $config = []) {}

    public static function default(): self
    {
        return new self([
            'spread_points' => 1.0,
            'point_value' => 0.0001,
            'commission_per_lot' => 7.0,
            'slippage_points' => 0.5,
            'swap_long_per_lot_per_day' => -0.5,
            'swap_short_per_lot_per_day' => -0.3,
        ]);
    }

    /** @return CostConfig */
    public function toArray(): array
    {
        return $this->config + self::default()->config;
    }

    public function applyEntry(string $direction, float $mid, float $volume): array
    {
        $cfg = $this->toArray();
        $halfSpread = ((float) $cfg['spread_points'] * (float) $cfg['point_value']) / 2;
        $slip = (float) $cfg['slippage_points'] * (float) $cfg['point_value'];
        $buy = strtoupper($direction) === 'BUY';
        $entry = $buy ? ($mid + $halfSpread + $slip) : ($mid - $halfSpread - $slip);
        $commission = (float) $cfg['commission_per_lot'] * $volume;
        $spreadCost = abs($halfSpread * 2) * $volume * 100000; // approximate FX notional factor for analytics

        return [
            'price' => $entry,
            'spread_cost' => $spreadCost,
            'commission' => $commission,
            'slippage' => abs($slip) * $volume * 100000,
            'swap' => 0.0,
        ];
    }

    public function applyExit(string $direction, float $mid, float $volume, float $holdingDays = 0.0): array
    {
        $cfg = $this->toArray();
        $halfSpread = ((float) $cfg['spread_points'] * (float) $cfg['point_value']) / 2;
        $slip = (float) $cfg['slippage_points'] * (float) $cfg['point_value'];
        $buy = strtoupper($direction) === 'BUY';
        // Exit at worse side of spread
        $exit = $buy ? ($mid - $halfSpread - $slip) : ($mid + $halfSpread + $slip);
        $swapKey = $buy ? 'swap_long_per_lot_per_day' : 'swap_short_per_lot_per_day';
        $swap = (float) $cfg[$swapKey] * $volume * max(0.0, $holdingDays);

        return [
            'price' => $exit,
            'spread_cost' => abs($halfSpread * 2) * $volume * 100000,
            'commission' => (float) $cfg['commission_per_lot'] * $volume,
            'slippage' => abs($slip) * $volume * 100000,
            'swap' => $swap,
        ];
    }
}
