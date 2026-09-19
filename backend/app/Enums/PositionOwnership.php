<?php

namespace App\Enums;

enum PositionOwnership: string
{
    case NexaManaged = 'NEXA_MANAGED';
    case Foreign = 'FOREIGN';
    case Manual = 'MANUAL';
    case OtherEa = 'OTHER_EA';
    case Unknown = 'UNKNOWN';
}
