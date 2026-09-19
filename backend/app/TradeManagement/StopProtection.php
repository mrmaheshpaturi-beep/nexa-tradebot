<?php

namespace App\TradeManagement;

use App\Enums\OrderDirection;

final class StopProtection
{
    /** Never loosen risk: buy stop only rises; sell stop only falls. */
    public static function isStrictImprovement(OrderDirection|string $direction, ?float $currentSl, float $proposedSl): bool
    {
        $dir = $direction instanceof OrderDirection ? $direction : OrderDirection::from($direction);
        if ($currentSl === null) {
            return true;
        }
        if ($dir === OrderDirection::Buy) {
            return $proposedSl > $currentSl;
        }

        return $proposedSl < $currentSl;
    }

    public static function clampNeverWorsen(OrderDirection|string $direction, ?float $currentSl, float $proposedSl): float
    {
        if ($currentSl === null) {
            return $proposedSl;
        }
        $dir = $direction instanceof OrderDirection ? $direction : OrderDirection::from($direction);
        if ($dir === OrderDirection::Buy) {
            return max($currentSl, $proposedSl);
        }

        return min($currentSl, $proposedSl);
    }

    public static function roundToDigits(float $price, int $digits): float
    {
        return round($price, $digits);
    }

    public static function rMultiple(OrderDirection|string $direction, float $entry, ?float $initialSl, float $mark): ?float
    {
        if ($initialSl === null || abs($entry - $initialSl) < 1e-12) {
            return null;
        }
        $dir = $direction instanceof OrderDirection ? $direction : OrderDirection::from($direction);
        $risk = abs($entry - $initialSl);
        $move = $dir === OrderDirection::Buy ? ($mark - $entry) : ($entry - $mark);

        return $move / $risk;
    }
}
