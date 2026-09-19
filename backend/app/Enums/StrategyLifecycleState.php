<?php

namespace App\Enums;

enum StrategyLifecycleState: string
{
    case Draft = 'DRAFT';
    case InReview = 'IN_REVIEW';
    case ReleaseCandidate = 'RELEASE_CANDIDATE';
    case Approved = 'APPROVED';
    case DeployedDemo = 'DEPLOYED_DEMO';
    case Suspended = 'SUSPENDED';
    case RolledBack = 'ROLLED_BACK';
    case Rejected = 'REJECTED';
    case Retired = 'RETIRED';

    /**
     * Allowed transitions — illegal jumps are rejected by LifecycleGuard.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Retired],
            self::InReview => [self::ReleaseCandidate, self::Draft, self::Rejected],
            self::ReleaseCandidate => [self::Approved, self::InReview, self::Rejected],
            self::Approved => [self::DeployedDemo, self::Suspended, self::Retired],
            self::DeployedDemo => [self::Suspended, self::RolledBack, self::Retired],
            self::Suspended => [self::DeployedDemo, self::Retired, self::RolledBack],
            self::Rejected => [self::Draft],
            self::RolledBack => [self::Approved, self::Retired, self::Draft],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
