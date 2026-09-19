<?php

namespace App\Enums;

enum ManagementStatus: string
{
    case Detected = 'DETECTED';
    case Eligible = 'ELIGIBLE';
    case Managing = 'MANAGING';
    case Paused = 'PAUSED';
    case Protecting = 'PROTECTING';
    case Closing = 'CLOSING';
    case Closed = 'CLOSED';
    case RequiresReview = 'REQUIRES_REVIEW';
    case Orphaned = 'ORPHANED';
    case ForeignIgnored = 'FOREIGN_IGNORED';
    case Blocked = 'BLOCKED';
    case Error = 'ERROR';

    /** @return list<string> */
    public static function activeManaging(): array
    {
        return [
            self::Managing->value,
            self::Protecting->value,
            self::Closing->value,
        ];
    }
}
