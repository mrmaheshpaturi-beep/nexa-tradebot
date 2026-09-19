<?php

namespace App\Enums;

enum ExecutionConfirmationStatus: string
{
    case Initiated = 'INITIATED';
    case Step1Complete = 'STEP1_COMPLETE';
    case Confirmed = 'CONFIRMED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Consumed = 'CONSUMED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Initiated => [self::Step1Complete, self::Expired, self::Cancelled],
            self::Step1Complete => [self::Confirmed, self::Expired, self::Cancelled],
            self::Confirmed => [self::Consumed, self::Expired, self::Cancelled],
            default => [],
        }, true);
    }
}
