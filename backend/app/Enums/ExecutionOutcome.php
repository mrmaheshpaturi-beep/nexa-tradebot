<?php

namespace App\Enums;

enum ExecutionOutcome: string
{
    case Accepted = 'ACCEPTED';
    case Filled = 'FILLED';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Rejected = 'REJECTED';
    case TimeoutUnknown = 'TIMEOUT_UNKNOWN';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
