<?php

namespace App\Enums;

enum SignalSource: string
{
    case Mock = 'MOCK';
    case Simulation = 'SIMULATION';
    case Strategy = 'STRATEGY';
}
