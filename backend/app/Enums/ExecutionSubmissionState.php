<?php

namespace App\Enums;

enum ExecutionSubmissionState: string
{
    case Pending = 'PENDING';
    case Locked = 'LOCKED';
    case OrderCheck = 'ORDER_CHECK';
    case Submitting = 'SUBMITTING';
    case Submitted = 'SUBMITTED';
    case Unknown = 'UNKNOWN';
    case Rejected = 'REJECTED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
