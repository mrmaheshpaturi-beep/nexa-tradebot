<?php

namespace App\Enums;

enum ExecutionFailureCode: string
{
    case SimulationExecutionFailed = 'SIMULATION_EXECUTION_FAILED';
    case AdapterRejected = 'ADAPTER_REJECTED';
    case AdapterTimeout = 'ADAPTER_TIMEOUT';
    case DemoVerificationFailed = 'DEMO_VERIFICATION_FAILED';
    case InvalidState = 'INVALID_STATE';
}
