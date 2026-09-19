<?php

namespace App\Enums;

enum PositionManagementActionStatus: string
{
    case Created = 'CREATED';
    case Locked = 'LOCKED';
    case Submitted = 'SUBMITTED';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
    case TimeoutUnknown = 'TIMEOUT_UNKNOWN';
    case Reconciled = 'RECONCILED';
    case Cancelled = 'CANCELLED';
}
