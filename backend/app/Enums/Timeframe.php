<?php

namespace App\Enums;

enum Timeframe: string
{
    case M1 = 'M1';
    case M5 = 'M5';
    case M15 = 'M15';
    case M30 = 'M30';
    case H1 = 'H1';
    case H4 = 'H4';
    case D1 = 'D1';
}
