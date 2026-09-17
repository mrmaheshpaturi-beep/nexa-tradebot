<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Simulated = 'SIMULATED';
    case Rejected = 'REJECTED';
}
