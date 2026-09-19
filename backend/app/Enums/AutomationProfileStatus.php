<?php

namespace App\Enums;

enum AutomationProfileStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
