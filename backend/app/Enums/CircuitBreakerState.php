<?php

namespace App\Enums;

enum CircuitBreakerState: string
{
    case Closed = 'CLOSED';
    case Open = 'OPEN';
    case HalfOpen = 'HALF_OPEN';
}
