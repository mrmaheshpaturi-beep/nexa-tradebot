<?php

namespace App\Enums;

enum OrderType: string
{
    case Market = 'MARKET';
    case BuyLimit = 'BUY_LIMIT';
    case SellLimit = 'SELL_LIMIT';
    case BuyStop = 'BUY_STOP';
    case SellStop = 'SELL_STOP';

    public function isPending(): bool
    {
        return $this !== self::Market;
    }

    public function side(): ?OrderDirection
    {
        return match ($this) {
            self::BuyLimit, self::BuyStop => OrderDirection::Buy,
            self::SellLimit, self::SellStop => OrderDirection::Sell,
            self::Market => null,
        };
    }
}
