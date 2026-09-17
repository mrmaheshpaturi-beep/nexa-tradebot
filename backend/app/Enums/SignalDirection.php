<?php

namespace App\Enums;

enum SignalDirection: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
    case Neutral = 'NEUTRAL';

    public function toOrderSide(): OrderDirection
    {
        return match ($this) {
            self::Buy => OrderDirection::Buy,
            self::Sell => OrderDirection::Sell,
            self::Neutral => throw new \DomainException('A NEUTRAL signal cannot create a trade intent.'),
        };
    }
}
