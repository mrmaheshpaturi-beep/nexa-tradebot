<?php

namespace App\Enums;

enum TradingReadiness: string
{
    case Ready = 'READY';
    case NotReady = 'NOT_READY';
}
