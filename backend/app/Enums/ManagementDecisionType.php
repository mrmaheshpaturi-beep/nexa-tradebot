<?php

namespace App\Enums;

enum ManagementDecisionType: string
{
    case Hold = 'HOLD';
    case MoveStop = 'MOVE_STOP';
    case MoveBreakEven = 'MOVE_BREAK_EVEN';
    case TrailStop = 'TRAIL_STOP';
    case UpdateTp = 'UPDATE_TP';
    case PartialClose = 'PARTIAL_CLOSE';
    case FullClose = 'FULL_CLOSE';
    case CancelPending = 'CANCEL_PENDING';
    case NoAction = 'NO_ACTION';
    case Blocked = 'BLOCKED';
}
