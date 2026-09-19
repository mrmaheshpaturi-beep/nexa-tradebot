<?php

namespace App\Enums;

enum AutomationQueueName: string
{
    case Safety = 'SAFETY';
    case Execution = 'EXECUTION';
    case Scan = 'SCAN';
    case Intelligence = 'INTELLIGENCE';
    case Analytics = 'ANALYTICS';

    /** Lower = higher priority. AI/intelligence never delays safety. */
    public function defaultPriority(): int
    {
        return match ($this) {
            self::Safety => 0,
            self::Execution => 10,
            self::Scan => 30,
            self::Analytics => 40,
            self::Intelligence => 50,
        };
    }
}
