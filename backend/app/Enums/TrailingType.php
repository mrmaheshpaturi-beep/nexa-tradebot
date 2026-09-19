<?php

namespace App\Enums;

enum TrailingType: string
{
    case FixedDistance = 'FIXED_DISTANCE';
    case AtrBased = 'ATR_BASED';
    case Percentage = 'PERCENTAGE';
    case StructureBased = 'STRUCTURE_BASED';
}
