<?php

namespace Tests\Unit;

use App\Enums\OrderDirection;
use App\Enums\TradingEnvironment;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_trading_enums_are_string_backed_and_simulation_only(): void
    {
        $this->assertSame('BUY', OrderDirection::Buy->value);
        $this->assertSame(['SIMULATION'], array_column(TradingEnvironment::cases(), 'value'));
    }

    public function test_service_safety_defaults_disable_all_execution(): void
    {
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['trading_enabled']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['auto_trading_enabled']);
        $this->assertTrue(SettingsService::SAFETY_DEFAULTS['emergency_stop']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_demo_execution']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_live_execution']);
    }
}
