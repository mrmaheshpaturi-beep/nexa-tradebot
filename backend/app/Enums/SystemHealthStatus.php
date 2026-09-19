<?php

namespace App\Enums;

enum SystemHealthStatus: string
{
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case Unhealthy = 'UNHEALTHY';
    case Unknown = 'UNKNOWN';
}
