<?php

namespace App\Services;

use App\Enums\OrderDirection;
use App\Models\TradingInstrument;

class FinancialCalculator
{
    private const EPSILON = 0.00000001;

    public function isVolumeValid(TradingInstrument $instrument, float $volume): bool
    {
        $minimum = (float) $instrument->minimum_volume;
        $maximum = (float) $instrument->maximum_volume;
        $step = (float) $instrument->step_volume;

        if ($volume < $minimum - self::EPSILON || $volume > $maximum + self::EPSILON || $step <= 0) {
            return false;
        }

        $steps = ($volume - $minimum) / $step;

        return abs($steps - round($steps)) < self::EPSILON;
    }

    public function profit(
        TradingInstrument $instrument,
        OrderDirection $side,
        float $volume,
        float $openPrice,
        float $closePrice,
    ): float {
        $direction = $side === OrderDirection::Buy ? 1 : -1;

        return round(($closePrice - $openPrice) * $direction * $volume * (float) $instrument->contract_size, 4);
    }

    public function margin(TradingInstrument $instrument, float $volume, float $price, int $leverage): float
    {
        return round($volume * (float) $instrument->contract_size * $price * (float) $instrument->margin_rate / max(1, $leverage), 4);
    }

    public function rewardRisk(OrderDirection $side, float $entry, ?float $stopLoss, ?float $takeProfit): ?float
    {
        if ($stopLoss === null || $takeProfit === null) {
            return null;
        }

        $risk = abs($entry - $stopLoss);
        if ($risk < self::EPSILON) {
            return null;
        }

        $reward = $side === OrderDirection::Buy ? $takeProfit - $entry : $entry - $takeProfit;

        return round($reward / $risk, 4);
    }

    public function riskAmount(TradingInstrument $instrument, float $volume, float $entry, ?float $stopLoss): float
    {
        if ($stopLoss === null) {
            return 0.0;
        }

        return round(abs($entry - $stopLoss) * $volume * (float) $instrument->contract_size, 4);
    }
}
