<?php

namespace App\Enums;

enum PositionEventType: string
{
    case Opened = 'OPENED';
    case VolumeIncreased = 'VOLUME_INCREASED';
    case PartiallyClosed = 'PARTIALLY_CLOSED';
    case StopLossModified = 'STOP_LOSS_MODIFIED';
    case TakeProfitModified = 'TAKE_PROFIT_MODIFIED';
    case BreakEvenApplied = 'BREAK_EVEN_APPLIED';
    case TrailingStopUpdated = 'TRAILING_STOP_UPDATED';
    case Closed = 'CLOSED';
    case Reconciled = 'RECONCILED';
}
