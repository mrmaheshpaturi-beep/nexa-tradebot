<?php

namespace App\Enums;

enum TradeIntentStatus: string
{
    case Draft = 'DRAFT';
    case PendingRisk = 'PENDING_RISK';
    case RiskApproved = 'RISK_APPROVED';
    case RiskRejected = 'RISK_REJECTED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case CommandCreated = 'COMMAND_CREATED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::PendingRisk, self::Cancelled],
            self::PendingRisk => [self::RiskApproved, self::RiskRejected, self::Cancelled, self::Expired],
            self::RiskApproved => [self::CommandCreated, self::Cancelled, self::Expired],
            default => [],
        }, true);
    }
}
