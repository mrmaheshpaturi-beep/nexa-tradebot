<?php

namespace App\Enums;

enum RiskLockType: string
{
    case DailyLoss = 'DAILY_LOSS';
    case WeeklyLoss = 'WEEKLY_LOSS';
    case Drawdown = 'DRAWDOWN';
    case LossStreak = 'LOSS_STREAK';
    case Margin = 'MARGIN';
    case Emergency = 'EMERGENCY';
    case Manual = 'MANUAL';
    case Exposure = 'EXPOSURE';
}
