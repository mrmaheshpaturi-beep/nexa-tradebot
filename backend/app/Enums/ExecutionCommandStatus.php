<?php

namespace App\Enums;

enum ExecutionCommandStatus: string
{
    case Created = 'CREATED';
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Acknowledged = 'ACKNOWLEDGED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Created => [self::Queued, self::Cancelled, self::Expired, self::Failed],
            self::Queued => [self::Processing, self::Cancelled, self::Expired, self::Failed],
            self::Processing => [self::Acknowledged, self::Failed],
            self::Acknowledged => [self::Completed, self::Failed],
            default => [],
        }, true);
    }
}
