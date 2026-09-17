<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'CREATED';
    case Submitted = 'SUBMITTED';
    case Accepted = 'ACCEPTED';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Filled = 'FILLED';
    case CancelPending = 'CANCEL_PENDING';
    case Cancelled = 'CANCELLED';
    case Simulated = 'SIMULATED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Created => [self::Submitted, self::Rejected, self::Failed, self::Expired],
            self::Submitted => [self::Accepted, self::Rejected, self::Failed, self::Expired],
            self::Accepted => [self::PartiallyFilled, self::Filled, self::CancelPending, self::Rejected, self::Expired, self::Failed],
            self::PartiallyFilled => [self::Filled, self::CancelPending, self::Expired, self::Failed],
            self::CancelPending => [self::Cancelled, self::Failed],
            self::Simulated => [self::Cancelled],
            default => [],
        }, true);
    }
}
