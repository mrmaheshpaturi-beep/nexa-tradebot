<?php

namespace App\Enums;

enum TimeInForce: string
{
    case GoodTillCancelled = 'GTC';
    case Day = 'DAY';
    case ImmediateOrCancel = 'IOC';
    case FillOrKill = 'FOK';
}
