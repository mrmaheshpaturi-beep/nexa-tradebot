<?php

namespace App\Enums;

/**
 * Watchdog responses. Never duplicates orders.
 */
enum WatchdogAction: string
{
    case Log = 'LOG';
    case Alert = 'ALERT';
    case Degrade = 'DEGRADE';
    case PauseNewEntries = 'PAUSE_NEW_ENTRIES';
    case SafeMode = 'SAFE_MODE';
}
