<?php

namespace App\Enums;

enum PositionStatus: string
{
    case Open = 'OPEN';
    case PartiallyClosed = 'PARTIALLY_CLOSED';
    case Closed = 'CLOSED';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => in_array($next, [self::PartiallyClosed, self::Closed], true),
            self::PartiallyClosed => in_array($next, [self::PartiallyClosed, self::Closed], true),
            self::Closed => false,
        };
    }
}
