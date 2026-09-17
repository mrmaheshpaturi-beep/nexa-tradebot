<?php

namespace App\Enums;

enum SignalStatus: string
{
    case Generated = 'GENERATED';
    case Valid = 'VALID';
    case Consumed = 'CONSUMED';
    case Expired = 'EXPIRED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
}
