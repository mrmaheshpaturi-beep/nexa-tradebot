<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
    case Emergency = 'EMERGENCY';
}
