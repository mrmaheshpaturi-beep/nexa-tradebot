<?php

namespace App\Enums;

/**
 * Automation modes. LIVE_AUTO deliberately does not exist.
 * UI path: OFF → DRY_RUN → DEMO_AUTO.
 */
enum AutomationMode: string
{
    case Off = 'OFF';
    case DryRun = 'DRY_RUN';
    case DemoAuto = 'DEMO_AUTO';

    public function allowsBrokerWrites(): bool
    {
        return $this === self::DemoAuto;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'OFF',
            self::DryRun => 'DRY RUN (ZERO BROKER)',
            self::DemoAuto => 'AUTO DEMO',
        };
    }
}
