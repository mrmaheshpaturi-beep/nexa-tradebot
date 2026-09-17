<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PhaseTwoSemanticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_schema_contains_required_phase_two_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('user_preferences', [
            'theme', 'sidebar_collapsed', 'default_dashboard', 'favorite_symbols',
            'default_timeframe', 'table_page_size',
        ]));
        $this->assertTrue(Schema::hasColumns('broker_accounts', [
            'broker', 'platform', 'server', 'environment', 'account_reference',
            'currency', 'leverage', 'status', 'is_enabled', 'last_connected_at', 'created_by',
        ]));
        $this->assertTrue(Schema::hasColumns('signals', [
            'market_regime', 'take_profit_1', 'take_profit_2', 'risk_reward',
            'status', 'source', 'generated_at', 'expires_at', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('orders', [
            'command_id', 'idempotency_key', 'risk_amount', 'risk_percent',
            'stop_loss', 'take_profit', 'comment', 'environment',
        ]));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'user_id', 'action', 'module', 'entity_type', 'entity_id', 'description',
            'result', 'ip_address', 'user_agent', 'occurred_at',
        ]));
        $this->assertTrue(Schema::hasColumns('account_snapshots', ['margin_level', 'floating_pnl']));
        $this->assertTrue(Schema::hasColumns('deals', ['order_id', 'position_id', 'broker_account_id', 'environment']));
        $this->assertTrue(Schema::hasColumns('positions', ['opening_order_id', 'broker_account_id', 'environment']));
    }

    public function test_preferences_are_validated_and_persisted(): void
    {
        $user = $this->userWithRole('TRADER');

        $this->actingAs($user)->putJson('/api/v1/preferences', [
            'theme' => 'dark',
            'sidebar_collapsed' => true,
            'default_dashboard' => 'risk',
            'favorite_symbols' => ['EURUSD', 'XAUUSD'],
            'default_timeframe' => 'M15',
            'table_page_size' => 50,
        ])->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.favorite_symbols.1', 'XAUUSD');

        $this->actingAs($user)->putJson('/api/v1/preferences', ['table_page_size' => 999])->assertUnprocessable();
    }

    public function test_all_thirteen_risk_limits_are_validated_and_persisted(): void
    {
        $user = $this->userWithRole();
        $payload = [
            'name' => 'Complete risk profile',
            'status' => 'ACTIVE',
            'is_default' => true,
            'max_risk_per_trade' => 1,
            'max_lot_size' => 5,
            'max_daily_loss' => 4,
            'max_weekly_loss' => 8,
            'max_drawdown' => 12,
            'max_open_positions' => 8,
            'max_open_risk' => 6,
            'max_trades_per_day' => 20,
            'max_consecutive_losses' => 4,
            'min_margin_level' => 300,
            'max_spread' => 3,
            'max_slippage' => 1.5,
            'min_reward_risk' => 1.5,
        ];

        $this->actingAs($user)->postJson('/api/v1/risk-profiles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $user->id);
        $this->assertDatabaseHas('risk_profiles', ['name' => 'Complete risk profile', 'is_default' => true]);

        $payload['min_margin_level'] = 99;
        $this->actingAs($user)->postJson('/api/v1/risk-profiles', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('min_margin_level');
    }

    public function test_strategy_domain_configuration_is_validated_and_versioned(): void
    {
        $user = $this->userWithRole();
        $payload = [
            'name' => 'London trend',
            'slug' => 'london-trend',
            'category' => 'TREND',
            'status' => 'DRAFT',
            'mode' => 'SIGNAL_ONLY',
            'minimum_signal_score' => 0.75,
            'symbols' => ['EURUSD'],
            'timeframes' => ['H1'],
            'sessions' => ['LONDON'],
            'parameters' => ['period' => 20],
            'configuration' => ['period' => 20],
        ];

        $response = $this->actingAs($user)->postJson('/api/v1/strategies', $payload)
            ->assertCreated()
            ->assertJsonPath('data.version', 1);
        $strategyId = $response->json('data.id');
        $payload['configuration'] = ['period' => 30];
        $payload['change_summary'] = 'Adjust period.';
        $this->actingAs($user)->putJson("/api/v1/strategies/{$strategyId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('strategy_versions', 2);
    }

    public function test_user_status_endpoints_are_audited(): void
    {
        $admin = $this->userWithRole();
        $user = User::factory()->create(['status' => 'ACTIVE']);

        $this->actingAs($admin)->postJson("/api/v1/users/{$user->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_updated', 'entity_id' => (string) $user->id]);
    }

    public function test_notification_read_state_is_persisted(): void
    {
        $user = $this->userWithRole('ANALYST');
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'risk',
            'category' => 'RISK',
            'severity' => 'WARNING',
            'title' => 'Risk notice',
            'message' => 'Simulation-only notice.',
        ]);

        $this->actingAs($user)->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_dashboard_returns_latest_persistent_account_snapshot(): void
    {
        $user = $this->userWithRole('VIEWER');
        $account = $user->brokerAccounts()->create(['name' => 'Simulation account']);
        $account->snapshots()->create([
            'balance' => 10000,
            'equity' => 9950,
            'margin' => 100,
            'free_margin' => 9850,
            'margin_level' => 9950,
            'floating_pnl' => -50,
            'drawdown' => 0.5,
            'captured_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.latest_account_snapshot.floating_pnl', -50)
            ->assertJsonPath('data.broker_accounts', 1);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $log = AuditLog::create([
            'action' => 'test.created',
            'module' => 'TEST',
            'description' => 'Immutable delete test.',
        ]);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
