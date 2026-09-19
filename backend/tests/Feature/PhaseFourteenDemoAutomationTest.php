<?php

namespace Tests\Feature;

use App\Automation\Support\AutomationSafety;
use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\TradingEnvironment;
use App\Models\ApplicationSetting;
use App\Models\AutomationProfile;
use App\Models\AutomationSession;
use App\Models\AutomationWorkflow;
use App\Models\BrokerAccount;
use App\Models\TradingInstrument;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseFourteenDemoAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_off_live_locked_auto_demo_unlockable(): void
    {
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['auto_demo_execution']);
        $this->assertFalse(SettingsService::SAFETY_DEFAULTS['allow_live_execution']);
        $this->assertContains('allow_live_execution', SettingsService::LOCKED_FALSE);
        $this->assertContains('auto_trading_enabled', SettingsService::LOCKED_FALSE);
        $this->assertNotContains('auto_demo_execution', SettingsService::LOCKED_FALSE);
        $this->assertFalse(AutomationSafety::ALLOWED_MODES === ['LIVE_AUTO']);
        $this->assertNotContains('LIVE_AUTO', AutomationSafety::ALLOWED_MODES);
        $this->assertSame(0, AutomationSafety::ORDER_SEND_CALL_SITES_IN_PHASE_14);
    }

    public function test_health_and_system_status_expose_phase_fourteen(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/automation/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 14)
            ->assertJsonPath('data.default_state', 'OFF')
            ->assertJsonPath('data.auto_start_on_boot', false)
            ->assertJsonPath('data.live_auto_exists', false)
            ->assertJsonPath('data.order_send_phase14', 0);

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.automated_trading_orchestrator.phase', 14)
            ->assertJsonPath('data.automated_trading_orchestrator.live_auto_exists', false)
            ->assertJsonPath('data.allow_live_execution', false);
    }

    public function test_live_auto_refused(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/automation/live-auto', [])
            ->assertStatus(403)
            ->assertJsonPath('data.live_auto_exists', false);
    }

    public function test_profile_validate_activate_and_dry_run_workflow_zero_broker(): void
    {
        $this->seedSafety(false);
        $user = $this->userWithRole('TRADER');
        $this->seedInstruments();

        $profile = $this->actingAs($user)->postJson('/api/v1/automation/profiles', [
            'name' => 'Dry Run Profile',
            'symbol_universe' => ['EURUSD'],
            'timeframe_universe' => ['H1'],
            'strategy_matrix' => [
                ['symbol' => 'EURUSD', 'timeframe' => 'H1', 'strategy_key' => 'ema_trend', 'strategy_version' => 'v1'],
            ],
            'intelligence_required' => false,
            'session_policy' => ['trade_weekends' => true, 'respect_symbol_hours' => true, 'respect_dst' => true],
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/automation/profiles/{$profile['public_id']}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'VALIDATED');
        $this->actingAs($user)->postJson("/api/v1/automation/profiles/{$profile['public_id']}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');

        $step1 = $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DRY_RUN',
            'confirmation_phrase' => 'ENABLE DRY RUN',
            'profile_public_id' => $profile['public_id'],
        ])->assertCreated()->json('data');

        $sessionId = $step1['session']['public_id'];
        $this->assertSame('STARTING', $step1['session']['state']);

        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sessionId}/start/step2", [
            'confirmation_phrase' => 'CONFIRM DRY RUN START',
        ])->assertOk()->assertJsonPath('data.state', 'RUNNING');

        $tick = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sessionId}/tick", [
            'candidate' => [
                'symbol' => 'EURUSD',
                'timeframe' => 'H1',
                'direction' => 'BUY',
                'strategy_key' => 'ema_trend',
                'strategy_version' => 'v1',
                'confluence_score' => 80,
                'candle_state' => 'CLOSED',
                'signal_at' => now()->toIso8601String(),
                'candle_id' => 'candle-1',
            ],
        ])->assertOk()->json('data');

        $this->assertNotNull($tick['workflow']);
        $this->assertSame('DRY_RUN_COMPLETE', $tick['workflow']['state']);
        $this->assertTrue($tick['workflow']['dry_run']);
        $this->assertFalse($tick['workflow']['broker_touched']);

        // Duplicate fingerprint blocked
        $dup = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sessionId}/tick", [
            'candidate' => [
                'symbol' => 'EURUSD',
                'timeframe' => 'H1',
                'direction' => 'BUY',
                'strategy_key' => 'ema_trend',
                'strategy_version' => 'v1',
                'confluence_score' => 80,
                'candle_state' => 'CLOSED',
                'signal_at' => now()->toIso8601String(),
                'candle_id' => 'candle-1',
            ],
        ])->assertOk()->json('data');
        $this->assertSame($tick['workflow']['public_id'], $dup['workflow']['public_id']);
        $this->assertSame(1, AutomationWorkflow::query()->count());
    }

    public function test_intelligence_required_wait_never_silent_bypass(): void
    {
        $this->seedSafety(false);
        $user = $this->userWithRole('TRADER');
        $this->seedInstruments();
        $profile = $this->makeActiveProfile($user, true);

        $step1 = $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DRY_RUN',
            'confirmation_phrase' => 'ENABLE DRY RUN',
            'profile_public_id' => $profile->public_id,
        ])->assertCreated()->json('data');
        $sessionId = $step1['session']['public_id'];
        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sessionId}/start/step2", [
            'confirmation_phrase' => 'CONFIRM DRY RUN START',
        ])->assertOk();

        $tick = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sessionId}/tick", [
            'candidate' => [
                'symbol' => 'EURUSD',
                'timeframe' => 'H1',
                'direction' => 'BUY',
                'strategy_key' => 'ema_trend',
                'strategy_version' => 'v1',
                'confluence_score' => 80,
                'candle_state' => 'CLOSED',
                'signal_at' => now()->toIso8601String(),
                'candle_id' => 'intel-wait-1',
            ],
        ])->assertOk()->json('data');

        $this->assertSame('INTELLIGENCE_WAIT', $tick['workflow']['state']);
        $this->assertFalse($tick['workflow']['broker_touched']);
    }

    public function test_demo_auto_requires_settings_and_two_step_phrases(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        [$account] = $this->demoFixtures($user);
        $profile = $this->makeActiveProfile($user, false);

        // Without auto_demo setting
        $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DEMO_AUTO',
            'confirmation_phrase' => 'ENABLE AUTO DEMO TRADING',
            'profile_public_id' => $profile->public_id,
            'broker_account_public_id' => $account->public_id,
        ])->assertStatus(422);

        // Enable auto demo via confirmation
        $this->actingAs($user)->postJson('/api/v1/automation/settings/enable-auto-demo', [
            'confirmation_phrase' => 'ENABLE AUTO DEMO TRADING',
        ])->assertOk()->assertJsonPath('data.value', true);

        $step1 = $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DEMO_AUTO',
            'confirmation_phrase' => 'ENABLE AUTO DEMO TRADING',
            'profile_public_id' => $profile->public_id,
            'broker_account_public_id' => $account->public_id,
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$step1['session']['public_id']}/start/step2", [
            'confirmation_phrase' => 'WRONG',
        ])->assertStatus(422);

        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$step1['session']['public_id']}/start/step2", [
            'confirmation_phrase' => 'CONFIRM AUTO DEMO START',
        ])->assertOk()->assertJsonPath('data.state', 'RUNNING')
            ->assertJsonPath('data.mode', 'DEMO_AUTO');
    }

    public function test_kill_switch_blocks_entries_no_close_all(): void
    {
        $this->seedSafety(false);
        $user = $this->userWithRole('TRADER');
        $profile = $this->makeActiveProfile($user, false);
        $step1 = $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DRY_RUN',
            'confirmation_phrase' => 'ENABLE DRY RUN',
            'profile_public_id' => $profile->public_id,
        ])->assertCreated()->json('data');
        $sid = $step1['session']['public_id'];
        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sid}/start/step2", [
            'confirmation_phrase' => 'CONFIRM DRY RUN START',
        ])->assertOk();

        $killed = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sid}/kill-switch")
            ->assertOk()->json('data');
        $this->assertTrue($killed['kill_switch']);
        $this->assertTrue($killed['auto_entry_paused']);
        $this->assertTrue($killed['entries_blocked']);

        $tick = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sid}/tick", [
            'candidate' => [
                'symbol' => 'EURUSD',
                'timeframe' => 'H1',
                'direction' => 'BUY',
                'strategy_key' => 'ema_trend',
                'strategy_version' => 'v1',
                'confluence_score' => 90,
                'candle_state' => 'CLOSED',
                'signal_at' => now()->toIso8601String(),
                'candle_id' => 'kill-1',
            ],
        ])->assertOk()->json('data');
        $this->assertTrue(in_array($tick['skipped'], ['AUTO_ENTRY_PAUSED', 'STATE_PAUSED'], true));
    }

    public function test_restart_recovery_pauses_entries(): void
    {
        $this->seedSafety(false);
        $user = $this->userWithRole('TRADER');
        $profile = $this->makeActiveProfile($user, false);
        $step1 = $this->actingAs($user)->postJson('/api/v1/automation/start/step1', [
            'mode' => 'DRY_RUN',
            'confirmation_phrase' => 'ENABLE DRY RUN',
            'profile_public_id' => $profile->public_id,
        ])->assertCreated()->json('data');
        $sid = $step1['session']['public_id'];
        $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sid}/start/step2", [
            'confirmation_phrase' => 'CONFIRM DRY RUN START',
        ])->assertOk();

        $recovered = $this->actingAs($user)->postJson("/api/v1/automation/sessions/{$sid}/recover")
            ->assertOk()->json('data');
        $this->assertSame('PAUSED', $recovered['state']);
        $this->assertTrue($recovered['auto_entry_paused']);
        $this->assertSame('RESTART_RECOVERY', $recovered['pause_reason']);
    }

    public function test_forbidden_mode_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AutomationSafety::assertModeAllowed('LIVE_AUTO');
    }

    public function test_phase14_source_has_no_order_send(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = shell_exec('rg -n "order_send\\s*\\(|authorized_order_send\\s*\\(|MetaTrader5\\.order_send|mt5\\.order_send" '
            .escapeshellarg($root.'/backend/app/Automation').' 2>/dev/null || true');
        $this->assertSame('', trim((string) $hits));
    }

    private function seedSafety(bool $demoEnabled): void
    {
        foreach ([
            'emergency_stop' => false,
            'simulation_execution_enabled' => true,
            'allow_demo_execution' => $demoEnabled,
            'auto_demo_execution' => false,
            'allow_live_execution' => false,
            'trading_enabled' => false,
        ] as $key => $value) {
            ApplicationSetting::updateOrCreate(['key' => $key], ['group' => 'trading', 'value' => $value, 'is_public' => true]);
        }
    }

    private function makeActiveProfile(User $user, bool $intelligenceRequired): AutomationProfile
    {
        $created = $this->actingAs($user)->postJson('/api/v1/automation/profiles', [
            'name' => 'Active Profile',
            'symbol_universe' => ['EURUSD'],
            'timeframe_universe' => ['H1'],
            'strategy_matrix' => [
                ['symbol' => 'EURUSD', 'timeframe' => 'H1', 'strategy_key' => 'ema_trend', 'strategy_version' => 'v1'],
            ],
            'intelligence_required' => $intelligenceRequired,
            'session_policy' => ['trade_weekends' => true, 'respect_symbol_hours' => true, 'respect_dst' => true],
            'qualification_rules' => [
                'min_confluence' => 60,
                'intelligence_required' => $intelligenceRequired,
                'ai_failure_policy' => 'WAIT',
            ],
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/automation/profiles/{$created['public_id']}/validate")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/automation/profiles/{$created['public_id']}/activate")->assertOk();

        return AutomationProfile::query()->where('public_id', $created['public_id'])->firstOrFail();
    }

    /** @return array{0:BrokerAccount,1:TradingInstrument} */
    private function demoFixtures(User $user): array
    {
        $this->seedInstruments();
        $profile = $user->riskProfiles()->create([
            'name' => 'Demo Profile',
            'status' => 'ACTIVE',
            'is_default' => true,
            'max_risk_per_trade' => 1,
            'max_lot_size' => 1,
            'max_daily_loss' => 5,
            'max_weekly_loss' => 10,
            'max_drawdown' => 20,
            'max_open_positions' => 8,
            'max_open_risk' => 5,
            'max_correlated_exposure' => 4,
            'max_trades_per_day' => 20,
            'max_consecutive_losses' => 4,
            'min_margin_level' => 300,
            'max_spread' => 50,
            'max_slippage' => 1.5,
            'min_reward_risk' => 1.5,
            'require_stop_loss' => true,
            'sizing_enabled' => true,
        ]);
        $account = $user->brokerAccounts()->create([
            'risk_profile_id' => $profile->id,
            'name' => 'Phase 14 DEMO',
            'environment' => TradingEnvironment::Demo,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
            'broker_login' => '900001',
            'broker_server' => 'Nexa-Demo',
            'verified_trade_mode' => 'DEMO',
            'demo_verified_at' => now(),
        ]);
        $account->snapshots()->create([
            'balance' => 10000,
            'equity' => 10000,
            'margin' => 0,
            'free_margin' => 10000,
            'floating_pnl' => 0,
            'drawdown' => 0,
            'captured_at' => now()->subMinute(),
        ]);
        $instrument = TradingInstrument::query()->where('symbol', 'EURUSD')->firstOrFail();

        return [$account, $instrument];
    }
}
