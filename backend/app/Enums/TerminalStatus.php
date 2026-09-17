<?php

namespace App\Enums;

enum TerminalStatus: string
{
    case Unknown = 'UNKNOWN';
    case Online = 'ONLINE';
    case Offline = 'OFFLINE';
    case Degraded = 'DEGRADED';
    case Error = 'ERROR';
}
