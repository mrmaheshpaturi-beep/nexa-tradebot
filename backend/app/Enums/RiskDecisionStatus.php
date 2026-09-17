<?php

namespace App\Enums;

enum RiskDecisionStatus: string
{
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
