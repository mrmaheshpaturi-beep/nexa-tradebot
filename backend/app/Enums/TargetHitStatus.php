<?php

namespace App\Enums;

enum TargetHitStatus: string
{
    case Pending = 'PENDING';
    case Hit = 'HIT';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Skipped = 'SKIPPED';
    case Cancelled = 'CANCELLED';
}
