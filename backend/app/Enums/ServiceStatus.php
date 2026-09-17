<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Unknown = 'UNKNOWN';
    case Online = 'ONLINE';
    case Degraded = 'DEGRADED';
    case Offline = 'OFFLINE';
    case Error = 'ERROR';
}
