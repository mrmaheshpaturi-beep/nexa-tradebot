<?php

namespace App\Enums;

enum TradeOrigin: string
{
    case Manual = 'MANUAL';
    case Signal = 'SIGNAL';
    case Simulation = 'SIMULATION';
    case System = 'SYSTEM';
    case Demo = 'DEMO';
}
