<?php

namespace App\Enums;

enum AutomationWorkflowState: string
{
    case CandidateReceived = 'CANDIDATE_RECEIVED';
    case DuplicateBlocked = 'DUPLICATE_BLOCKED';
    case IntelligencePending = 'INTELLIGENCE_PENDING';
    case IntelligenceWait = 'INTELLIGENCE_WAIT';
    case Qualifying = 'QUALIFYING';
    case Qualified = 'QUALIFIED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case RiskPending = 'RISK_PENDING';
    case RiskApproved = 'RISK_APPROVED';
    case RiskRejected = 'RISK_REJECTED';
    case ExecutionPending = 'EXECUTION_PENDING';
    case ExecutionSubmitted = 'EXECUTION_SUBMITTED';
    case ExecutionUnknown = 'EXECUTION_UNKNOWN';
    case ExecutionFilled = 'EXECUTION_FILLED';
    case ExecutionRejected = 'EXECUTION_REJECTED';
    case DryRunComplete = 'DRY_RUN_COMPLETE';
    case ManagementActive = 'MANAGEMENT_ACTIVE';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
