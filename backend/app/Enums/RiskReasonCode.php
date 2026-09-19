<?php

namespace App\Enums;

enum RiskReasonCode: string
{
    case Approved = 'APPROVED';
    case DailyLossLimit = 'DAILY_LOSS_LIMIT';
    case WeeklyLossLimit = 'WEEKLY_LOSS_LIMIT';
    case DrawdownLimit = 'DRAWDOWN_LIMIT';
    case MaxPositions = 'MAX_POSITIONS';
    case MaxExposure = 'MAX_EXPOSURE';
    case MaxLot = 'MAX_LOT';
    case SpreadLimit = 'SPREAD_LIMIT';
    case SlippageLimit = 'SLIPPAGE_LIMIT';
    case MarginLimit = 'MARGIN_LIMIT';
    case ConsecutiveLossLimit = 'CONSECUTIVE_LOSS_LIMIT';
    case MinimumRiskReward = 'MINIMUM_RR';
    case EmergencyStop = 'EMERGENCY_STOP';
    case TradingDisabled = 'TRADING_DISABLED';
    case SessionRestricted = 'SESSION_RESTRICTED';
    case NewsRestricted = 'NEWS_RESTRICTED';
    case CorrelationLimit = 'CORRELATION_LIMIT';
    case ValidationFailure = 'VALIDATION_FAILURE';
    case SimulationDisabled = 'SIMULATION_EXECUTION_DISABLED';
    case InvalidEnvironment = 'INVALID_ENVIRONMENT';
    case InvalidAccount = 'INVALID_ACCOUNT';
    case InvalidInstrument = 'INVALID_INSTRUMENT';
    case InvalidVolume = 'INVALID_VOLUME';
    case InvalidProtection = 'INVALID_PROTECTION';
    case RiskLimit = 'RISK_LIMIT';
    case RewardRisk = 'REWARD_RISK';
    case MaxOpenPositions = 'MAX_OPEN_POSITIONS';
    case MissingSnapshot = 'MISSING_ACCOUNT_SNAPSHOT';
    case RiskLock = 'RISK_LOCK';
    case MissingSymbolSpecs = 'MISSING_SYMBOL_SPECS';
    case DataQuality = 'DATA_QUALITY';
    case StopDistance = 'STOP_DISTANCE';
    case ReservationConflict = 'RESERVATION_CONFLICT';
    case FailClosed = 'FAIL_CLOSED';
    case InsufficientEquity = 'INSUFFICIENT_EQUITY';
}
