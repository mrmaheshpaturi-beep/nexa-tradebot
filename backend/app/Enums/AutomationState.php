<?php

namespace App\Enums;

/** Orchestrator runtime state. Default after install: OFF. Never auto-start on boot. */
enum AutomationState: string
{
    case Off = 'OFF';
    case Starting = 'STARTING';
    case Running = 'RUNNING';
    case Paused = 'PAUSED';
    case Degraded = 'DEGRADED';
    case SafeMode = 'SAFE_MODE';
    case Stopping = 'STOPPING';
    case Error = 'ERROR';
}
