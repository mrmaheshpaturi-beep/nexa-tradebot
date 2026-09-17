<?php

namespace App\Enums;

enum OrderType: string
{
    case Market = 'MARKET';
    case Limit = 'LIMIT';
    case Stop = 'STOP';
    case StopLimit = 'STOP_LIMIT';

    public function isPending(): bool
    {
        return $this !== self::Market;
    }
}
