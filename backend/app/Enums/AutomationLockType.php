<?php

namespace App\Enums;

/**
 * Lock precedence (lower number = higher priority).
 * KILL > SAFE_MODE > DAILY_LOSS/DRAWDOWN/LOSS_STREAK > ENTRY/SYMBOL > SCHEDULER
 */
enum AutomationLockType: string
{
    case Kill = 'KILL';
    case SafeMode = 'SAFE_MODE';
    case DailyLoss = 'DAILY_LOSS';
    case Drawdown = 'DRAWDOWN';
    case LossStreak = 'LOSS_STREAK';
    case Entry = 'ENTRY';
    case Symbol = 'SYMBOL';
    case Session = 'SESSION';
    case Scheduler = 'SCHEDULER';

    public function precedence(): int
    {
        return match ($this) {
            self::Kill => 0,
            self::SafeMode => 10,
            self::DailyLoss, self::Drawdown, self::LossStreak => 20,
            self::Entry, self::Session => 40,
            self::Symbol => 50,
            self::Scheduler => 80,
        };
    }
}
