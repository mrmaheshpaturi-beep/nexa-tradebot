<?php

namespace App\Enums;

enum CloseReason: string
{
    case Manual = 'MANUAL';
    case TakeProfit = 'TAKE_PROFIT';
    case StopLoss = 'STOP_LOSS';
    case BreakEven = 'BREAK_EVEN';
    case TrailingStop = 'TRAILING_STOP';
    case PartialThenFull = 'PARTIAL_THEN_FULL';
    case StrategyInvalidation = 'STRATEGY_INVALIDATION';
    case TimeExit = 'TIME_EXIT';
    case SessionExit = 'SESSION_EXIT';
    case WeekendPolicy = 'WEEKEND_POLICY';
    case RiskExit = 'RISK_EXIT';
    case EmergencyExit = 'EMERGENCY_EXIT';
    case ExternalMt5 = 'EXTERNAL_MT5';
    case BrokerSlHit = 'BROKER_SL_HIT';
    case BrokerTpHit = 'BROKER_TP_HIT';
    case Unknown = 'UNKNOWN';
}
