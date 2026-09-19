<?php

namespace App\Enums;

enum ProposedPlanStatus: string
{
    case Proposed = 'PROPOSED';
    case Superseded = 'SUPERSEDED';
    case Rejected = 'REJECTED';
    case AcceptedForSimulation = 'ACCEPTED_FOR_SIMULATION';
}
