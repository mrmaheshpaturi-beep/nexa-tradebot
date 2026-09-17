<?php

namespace App\Enums;

enum ExecutionFailureCode: string
{
    case SimulationExecutionFailed = 'SIMULATION_EXECUTION_FAILED';
    case AdapterRejected = 'ADAPTER_REJECTED';
    case InvalidState = 'INVALID_STATE';
}
