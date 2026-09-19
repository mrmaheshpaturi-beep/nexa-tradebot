<?php

namespace App\Enums;

enum TradingEnvironment: string
{
    case Simulation = 'SIMULATION';
    case Paper = 'PAPER';
    case Demo = 'DEMO';
    case Live = 'LIVE';
    /** Research-only. Never broker-routable; never mixed with DEMO labels. */
    case Backtest = 'BACKTEST';

    public function isExecutable(): bool
    {
        return $this === self::Simulation;
    }

    public function isBrokerRoutable(): bool
    {
        return false;
    }
}
