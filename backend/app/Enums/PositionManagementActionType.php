<?php

namespace App\Enums;

enum PositionManagementActionType: string
{
    case ModifySl = 'MODIFY_SL';
    case ModifyTp = 'MODIFY_TP';
    case ModifySlTp = 'MODIFY_SL_TP';
    case PartialClose = 'PARTIAL_CLOSE';
    case FullClose = 'FULL_CLOSE';
    case CancelPending = 'CANCEL_PENDING';
}
