<?php

namespace App\Enums;

enum TradingEnvironment: string
{
    case Simulation = 'SIMULATION';
    case Paper = 'PAPER';
    case Demo = 'DEMO';
    case Live = 'LIVE';

    public function isExecutable(): bool
    {
        return $this === self::Simulation;
    }
}
