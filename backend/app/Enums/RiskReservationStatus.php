<?php

namespace App\Enums;

enum RiskReservationStatus: string
{
    case Active = 'ACTIVE';
    case Released = 'RELEASED';
    case Expired = 'EXPIRED';
    case Consumed = 'CONSUMED';
}
