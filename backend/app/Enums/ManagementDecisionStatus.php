<?php

namespace App\Enums;

enum ManagementDecisionStatus: string
{
    case Proposed = 'PROPOSED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Executing = 'EXECUTING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Superseded = 'SUPERSEDED';
    case Unknown = 'UNKNOWN';
}
