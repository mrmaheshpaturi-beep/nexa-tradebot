<?php

namespace Tests\Unit;

use App\Enums\ExecutionCommandStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\OrderDirection;
use App\Enums\OrderStatus;
use App\Enums\PositionEventType;
use App\Enums\PositionStatus;
use App\Enums\SignalDirection;
use App\Enums\SignalStatus;
use App\Enums\TerminalStatus;
use App\Enums\TradeIntentStatus;
use App\Enums\TradingAssetClass;
use App\Enums\TradingEnvironment;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_environment_vocabulary_is_expanded_but_only_simulation_is_executable(): void
    {
        $this->assertSame('BUY', OrderDirection::Buy->value);
        $this->assertSame(['SIMULATION', 'PAPER', 'DEMO', 'LIVE'], array_column(TradingEnvironment::cases(), 'value'));
        $this->assertSame(
            ['SIMULATION'],
            array_values(array_map(
                fn (TradingEnvironment $environment): string => $environment->value,
                array_filter(TradingEnvironment::cases(), fn (TradingEnvironment $environment): bool => $environment->isExecutable()),
            )),
        );
    }

    public function test_service_safety_defaults_disable_all_execution(): void
    {
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['trading_enabled']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['simulation_execution_enabled']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['auto_trading_enabled']);
        $this->assertTrue(SettingsService::SAFETY_DEFAULTS['emergency_stop']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_demo_execution']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_live_execution']);
    }

    public function test_phase_three_lifecycle_enums_use_the_canonical_vocabulary(): void
    {
        $this->assertSame(
            ['DRAFT', 'PENDING_RISK', 'RISK_APPROVED', 'RISK_REJECTED', 'CANCELLED', 'EXPIRED', 'COMMAND_CREATED'],
            array_column(TradeIntentStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['PLACE_ORDER', 'MODIFY_ORDER', 'CANCEL_ORDER', 'CLOSE_POSITION', 'PARTIAL_CLOSE', 'MODIFY_POSITION_SL', 'MODIFY_POSITION_TP'],
            array_column(ExecutionCommandType::cases(), 'value'),
        );
        $this->assertSame(
            ['CREATED', 'QUEUED', 'PROCESSING', 'ACKNOWLEDGED', 'COMPLETED', 'FAILED', 'CANCELLED', 'EXPIRED'],
            array_column(ExecutionCommandStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['CREATED', 'SUBMITTED', 'ACCEPTED', 'PARTIALLY_FILLED', 'FILLED', 'CANCEL_PENDING', 'CANCELLED', 'SIMULATED', 'REJECTED', 'EXPIRED', 'FAILED'],
            array_column(OrderStatus::cases(), 'value'),
        );
        $this->assertSame(['OPEN', 'PARTIALLY_CLOSED', 'CLOSED'], array_column(PositionStatus::cases(), 'value'));
        $this->assertSame(
            ['OPENED', 'VOLUME_INCREASED', 'PARTIALLY_CLOSED', 'STOP_LOSS_MODIFIED', 'TAKE_PROFIT_MODIFIED', 'BREAK_EVEN_APPLIED', 'TRAILING_STOP_UPDATED', 'CLOSED', 'RECONCILED'],
            array_column(PositionEventType::cases(), 'value'),
        );
        $this->assertSame(
            ['GENERATED', 'VALID', 'CONSUMED', 'EXPIRED', 'REJECTED', 'CANCELLED'],
            array_column(SignalStatus::cases(), 'value'),
        );
        $this->assertSame(['BUY', 'SELL', 'NEUTRAL'], array_column(SignalDirection::cases(), 'value'));
        $this->assertSame(
            ['UNKNOWN', 'ONLINE', 'OFFLINE', 'DEGRADED', 'ERROR'],
            array_column(TerminalStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['FOREX', 'METAL', 'INDEX', 'CRYPTO', 'COMMODITY', 'OTHER', 'EQUITY'],
            array_column(TradingAssetClass::cases(), 'value'),
        );
    }
}
