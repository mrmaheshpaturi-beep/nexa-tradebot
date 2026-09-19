<?php

namespace App\Enums;

enum ManualChangeDisposition: string
{
    case Adopt = 'ADOPT';
    case Alert = 'ALERT';
    case RequiresReview = 'REQUIRES_REVIEW';
}
