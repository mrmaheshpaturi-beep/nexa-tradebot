<?php

namespace App\Enums;

enum ExecutionCommandType: string
{
    case PlaceOrder = 'PLACE_ORDER';
    case ModifyOrder = 'MODIFY_ORDER';
    case CancelOrder = 'CANCEL_ORDER';
    case ClosePosition = 'CLOSE_POSITION';
    case PartialClose = 'PARTIAL_CLOSE';
    case ModifyPositionStopLoss = 'MODIFY_POSITION_SL';
    case ModifyPositionTakeProfit = 'MODIFY_POSITION_TP';
}
