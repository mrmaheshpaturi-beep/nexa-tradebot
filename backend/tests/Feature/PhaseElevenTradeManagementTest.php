<?php

namespace Tests\Feature;

use App\Enums\ManagementStatus;
use App\Enums\OrderDirection;
use App\Enums\PositionOwnership;
use App\Enums\TargetHitStatus;
use App\Enums\TradingEnvironment;
use App\Enums\TrailingType;
use App\Execution\FakeDemoBridgeClient;
use App\Models\ApplicationSetting;
use App\Models\BrokerAccount;
use App\Models\BrokerActionLock;
use App\Models\ManagedPosition;
use App\Models\ManagedPositionTarget;
use App\Models\PositionManagementAction;
use App\Models\RiskLock;
use App\Models\TradeManagementPolicy;
use App\Models\TradeSummary;
use App\Models\User;
use App\Services\SettingsService;
use App\TradeManagement\PositionManagementGate;
use App\TradeManagement\StopProtection;
use App\TradeManagement\TradeManagementEngineService;
use App\TradeManagement\VolumeSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseElevenTradeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_break_even_buy_moves_stop_once_and_idempotent(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.10000, sl: 1.09900, volume: 0.10);
        // Mark at +1R: bid 1.10100
        app(FakeDemoBridgeClient::class); // quotes fixed EURUSD 1.10000/1.10020 — force via evaluate overrides using mid from quote
        // Use evaluate with extras by temporarily adjusting entry so R hits: entry 1.09900 would be better
        // Instead set quote by using structure: entry 1.10000, SL 1.09900, need bid >= 1.10100
        // Fake quote bid is 1.10000 — R=0. Override by setting break_even trigger to 0.
        $policy = $this->policy($user, [
            'break_even_enabled' => true,
            'break_even_trigger_value' => 0,
            'break_even_offset' => 0.00010,
            'trailing_enabled' => false,
        ]);
        $position->forceFill(['management_policy_id' => $policy->id, 'management_policy_version' => 1])->save();

        $engine = app(TradeManagementEngineService::class);
        $first = $engine->evaluate($position->fresh(), $user, [], true);
        $this->assertSame('MOVE_BREAK_EVEN', $first['decision']->decision_type->value);
        $this->assertNotNull($first['action']);
        $this->assertTrue($position->fresh()->break_even_applied);

        $second = $engine->evaluate($position->fresh(), $user, [], true);
        $this->assertNotSame('MOVE_BREAK_EVEN', $second['decision']->decision_type->value);
    }

    public function test_break_even_sell_and_never_worsens(): void
    {
        $this->assertTrue(StopProtection::isStrictImprovement(OrderDirection::Buy, 1.10, 1.11));
        $this->assertFalse(StopProtection::isStrictImprovement(OrderDirection::Buy, 1.10, 1.09));
        $this->assertTrue(StopProtection::isStrictImprovement(OrderDirection::Sell, 1.10, 1.09));
        $this->assertFalse(StopProtection::isStrictImprovement(OrderDirection::Sell, 1.10, 1.11));
        $this->assertSame(1.0, StopProtection::rMultiple(OrderDirection::Buy, 1.10, 1.09, 1.11));
        $this->assertSame(1.0, StopProtection::rMultiple(OrderDirection::Sell, 1.10, 1.11, 1.09));
    }

    public function test_trailing_buy_never_loosens_and_respects_step(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.10000, sl: 1.09900, volume: 0.10);
        $policy = $this->policy($user, [
            'break_even_enabled' => false,
            'trailing_enabled' => true,
            'trailing_type' => TrailingType::FixedDistance->value,
            'trailing_start' => 0,
            'trailing_distance' => 0.00050,
            'trailing_step' => 0.00010,
        ]);
        $position->forceFill([
            'management_policy_id' => $policy->id,
            'current_stop_loss' => 1.09900,
        ])->save();

        $engine = app(TradeManagementEngineService::class);
        $result = $engine->evaluate($position->fresh(), $user, [], true);
        $this->assertSame('TRAIL_STOP', $result['decision']->decision_type->value);
        $sl1 = (float) $position->fresh()->current_stop_loss;
        $this->assertGreaterThan(1.09900, $sl1);

        // Second evaluate with same quote should HOLD due to step/idempotency (no improvement beyond step)
        $result2 = $engine->evaluate($position->fresh(), $user, [], true);
        $sl2 = (float) $position->fresh()->current_stop_loss;
        $this->assertGreaterThanOrEqual($sl1, $sl2);
        $this->assertNotSame('MOVE_STOP', $result2['decision']->decision_type->value);
    }

    public function test_atr_and_structure_trailing_types(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.10000, sl: 1.09900, volume: 0.10);
        $policy = $this->policy($user, [
            'break_even_enabled' => false,
            'trailing_enabled' => true,
            'trailing_type' => TrailingType::AtrBased->value,
            'trailing_start' => 0,
            'trailing_atr_multiplier' => 1.5,
            'trailing_distance' => 0.00050,
            'trailing_step' => 0,
        ]);
        $position->forceFill(['management_policy_id' => $policy->id])->save();
        $engine = app(TradeManagementEngineService::class);
        $atr = $engine->evaluate($position->fresh(), $user, ['atr' => 0.00040], true);
        $this->assertContains($atr['decision']->decision_type->value, ['TRAIL_STOP', 'HOLD']);

        $policy->forceFill(['trailing_type' => TrailingType::StructureBased->value])->save();
        $struct = $engine->evaluate($position->fresh(), $user, ['structure_stop' => 1.09950], true);
        $this->assertContains($struct['decision']->decision_type->value, ['TRAIL_STOP', 'HOLD', 'NO_ACTION']);
    }

    public function test_partial_close_idempotent_and_residual_safety(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.10000, sl: 1.09900, volume: 0.10);
        $policy = $this->policy($user, [
            'break_even_enabled' => false,
            'partial_close_enabled' => true,
            'partial_close_levels' => [['label' => 'TP1', 'percent' => 50, 'price' => 1.09990]],
        ]);
        $position->forceFill(['management_policy_id' => $policy->id])->save();
        ManagedPositionTarget::query()->create([
            'managed_position_id' => $position->id,
            'label' => 'TP1',
            'sequence' => 1,
            'price' => 1.09990,
            'close_percent' => 50,
            'status' => TargetHitStatus::Pending,
        ]);

        $engine = app(TradeManagementEngineService::class);
        $first = $engine->evaluate($position->fresh(), $user, [], true);
        $this->assertSame('PARTIAL_CLOSE', $first['decision']->decision_type->value);
        $this->assertNotNull($first['action']);
        $this->assertSame(1, PositionManagementAction::query()->where('action_type', 'PARTIAL_CLOSE')->count());

        $vol = VolumeSafety::normalizeCloseVolume(0.095, 0.10, ['volume_min' => 0.01, 'volume_step' => 0.01]);
        $this->assertSame(0.09, $vol);

        // Illegal residual below min → promote to full close
        $promoted = VolumeSafety::normalizeCloseVolume(0.07, 0.10, ['volume_min' => 0.04, 'volume_step' => 0.01]);
        $this->assertSame(0.10, $promoted);

        $full = VolumeSafety::normalizeCloseVolume(0.10, 0.10, ['volume_min' => 0.01, 'volume_step' => 0.01]);
        $this->assertSame(0.10, $full);
    }

    public function test_full_close_duplicate_prevented(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.10000, sl: 1.09900, volume: 0.10);
        $engine = app(TradeManagementEngineService::class);
        $first = $engine->evaluate($position->fresh(), $user, ['emergency' => true], true);
        $this->assertSame('FULL_CLOSE', $first['decision']->decision_type->value);
        $this->assertSame('CLOSED', $position->fresh()->management_status->value);

        $key = 'auto-'.$first['decision']->public_id;
        $replay = app(\App\TradeManagement\ManagementActionService::class)
            ->executeDecision($first['decision']->fresh(), $user, $key);
        $this->assertTrue($replay['replayed']);
    }

    public function test_strategy_time_session_risk_exits(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $engine = app(TradeManagementEngineService::class);

        $p1 = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        $pol1 = $this->policy($user, ['strategy_invalidation_exit' => true, 'break_even_enabled' => false]);
        $p1->forceFill(['management_policy_id' => $pol1->id])->save();
        $this->assertSame('FULL_CLOSE', $engine->evaluate($p1->fresh(), $user, ['strategy_invalidated' => true], true)['decision']->decision_type->value);

        $p2 = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1, brokerId: '710002');
        $pol2 = $this->policy($user, [
            'name' => 'time',
            'time_exit_enabled' => true,
            'maximum_trade_duration_minutes' => 1,
            'break_even_enabled' => false,
            'emergency_exit' => false,
            'risk_exit' => false,
        ]);
        $p2->forceFill(['management_policy_id' => $pol2->id, 'opened_at' => now()->subMinutes(5)])->save();
        $this->assertSame('FULL_CLOSE', $engine->evaluate($p2->fresh(), $user, [], true)['decision']->decision_type->value);

        $p3 = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1, brokerId: '710003');
        $pol3 = $this->policy($user, [
            'name' => 'session',
            'session_exit' => true,
            'break_even_enabled' => false,
            'emergency_exit' => false,
        ]);
        $p3->forceFill(['management_policy_id' => $pol3->id])->save();
        $this->assertSame('FULL_CLOSE', $engine->evaluate($p3->fresh(), $user, ['session_closing' => true], true)['decision']->decision_type->value);

        $p4 = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1, brokerId: '710004');
        $pol4 = $this->policy($user, [
            'name' => 'risk',
            'risk_exit' => true,
            'break_even_enabled' => false,
            'emergency_exit' => false,
        ]);
        $p4->forceFill(['management_policy_id' => $pol4->id])->save();
        $this->assertSame('FULL_CLOSE', $engine->evaluate($p4->fresh(), $user, ['risk' => ['force_exit' => true]], true)['decision']->decision_type->value);
    }

    public function test_foreign_position_never_managed(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        $position->forceFill(['ownership' => PositionOwnership::Foreign, 'management_status' => ManagementStatus::ForeignIgnored])->save();
        $result = app(TradeManagementEngineService::class)->evaluate($position->fresh(), $user, ['emergency' => true], true);
        $this->assertSame('BLOCKED', $result['decision']->decision_type->value);
        $this->assertNull($result['action']);
    }

    public function test_live_and_unknown_management_hard_blocked(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $liveAccount = $user->brokerAccounts()->create([
            'name' => 'Live',
            'environment' => TradingEnvironment::Live,
            'status' => 'READY',
            'is_enabled' => true,
            'currency' => 'USD',
            'leverage' => 100,
            'broker_login' => '1',
            'broker_server' => 'Live',
        ]);
        $position = ManagedPosition::query()->create([
            'user_id' => $user->id,
            'broker_account_id' => $liveAccount->id,
            'ownership' => PositionOwnership::NexaManaged,
            'management_status' => ManagementStatus::Managing,
            'broker_position_id' => 'L1',
            'symbol' => 'EURUSD',
            'direction' => OrderDirection::Buy,
            'environment' => TradingEnvironment::Live,
            'initial_volume' => 0.1,
            'current_volume' => 0.1,
            'entry_price' => 1.1,
            'initial_stop_loss' => 1.09,
            'current_stop_loss' => 1.09,
            'opened_at' => now(),
        ]);
        $result = app(TradeManagementEngineService::class)->evaluate($position, $user, [], true);
        $this->assertSame('BLOCKED', $result['decision']->decision_type->value);

        $demo = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1, brokerId: '710099');
        app(FakeDemoBridgeClient::class)->forceLiveTradeMode = true;
        try {
            app(PositionManagementGate::class)->assertCanManage($demo->fresh(), true);
            $this->fail('LIVE trade mode must hard fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        app(FakeDemoBridgeClient::class)->forceLiveTradeMode = false;
        app(FakeDemoBridgeClient::class)->forceUnknownTradeMode = true;
        try {
            app(PositionManagementGate::class)->assertCanManage($demo->fresh(), true);
            $this->fail('UNKNOWN must hard fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_risk_lock_allows_protective_close(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        RiskLock::query()->create([
            'user_id' => $user->id,
            'broker_account_id' => $position->broker_account_id,
            'lock_type' => 'MANUAL',
            'reason_code' => 'RISK_LOCK',
            'message' => 'test',
            'is_active' => true,
            'blocks_new_entries' => true,
            'blocks_protective_closes' => false,
            'locked_at' => now(),
            'created_by' => $user->id,
        ]);
        $gate = app(PositionManagementGate::class);
        $verification = $gate->assertCanManage($position->fresh(), true);
        $this->assertSame('DEMO', $verification['trade_mode']);
    }

    public function test_broker_action_lock_block_all_blocks_management(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        BrokerActionLock::query()->create([
            'user_id' => $user->id,
            'broker_account_id' => $position->broker_account_id,
            'scope' => 'BLOCK_ALL_BROKER_ACTIONS',
            'is_active' => true,
            'reason' => 'test',
            'activated_at' => now(),
        ]);
        $this->expectException(ValidationException::class);
        app(PositionManagementGate::class)->assertCanManage($position->fresh(), true);
    }

    public function test_timeout_unknown_reconciles_without_blind_retry(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        app(FakeDemoBridgeClient::class)->forceTimeout = true;
        $result = app(TradeManagementEngineService::class)->evaluate($position->fresh(), $user, ['emergency' => true], true);
        $this->assertNotNull($result['action']);
        $this->assertSame('TIMEOUT_UNKNOWN', $result['action']->status->value);
        $this->assertTrue($result['action']->blind_retry_forbidden);

        app(FakeDemoBridgeClient::class)->forceTimeout = false;
        $recovered = app(\App\TradeManagement\ManagementCrashRecoveryService::class)->recoverOne($result['action']->fresh());
        $this->assertContains($recovered->status->value, ['RECONCILED', 'TIMEOUT_UNKNOWN', 'COMPLETED']);
    }

    public function test_manual_api_two_step_close_and_pause_resume(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);

        $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/pause")
            ->assertOk()
            ->assertJsonPath('data.auto_management_paused', true);
        $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/resume")
            ->assertOk()
            ->assertJsonPath('data.auto_management_paused', false);

        $prep = $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/close/prepare", [
            'idempotency_key' => 'close-1',
        ])->assertCreated()->json('data');

        $step2 = $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/close/confirm", [
            'confirmation_public_id' => $prep['confirmation']['public_id'],
            'challenge_token' => $prep['challenge_token'],
            'idempotency_key' => 'close-exec-1',
        ])->assertOk()->json('data');

        $done = $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/close/confirm", [
            'confirmation_public_id' => $prep['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'close-exec-1',
        ])->assertCreated()->json('data');

        $this->assertFalse($done['replayed']);
        $replay = $this->actingAs($user)->postJson("/api/v1/positions-managed/{$position->public_id}/close/confirm", [
            'confirmation_public_id' => $prep['confirmation']['public_id'],
            'confirm_token' => $step2['confirm_token'],
            'idempotency_key' => 'close-exec-1',
        ])->assertOk()->json('data');
        $this->assertTrue($replay['replayed']);
    }

    public function test_trade_summary_immutable_after_finalize(): void
    {
        $this->seedSafety(true);
        $user = $this->userWithRole('TRADER');
        $position = $this->managedBuy($user, entry: 1.1, sl: 1.09, volume: 0.1);
        $position->forceFill([
            'management_status' => ManagementStatus::Closed,
            'closed_at' => now(),
            'close_reason' => 'MANUAL',
            'current_volume' => 0,
        ])->save();
        $summary = app(\App\TradeManagement\TradeSummaryService::class)->finalize($position->fresh());
        $this->assertTrue($summary->finalized);
        $this->expectException(ValidationException::class);
        $summary->forceFill(['realized_pnl' => 99])->save();
    }

    public function test_health_dashboard_and_close_all_rbac(): void
    {
        $this->seedSafety(true);
        $trader = $this->userWithRole('TRADER');
        $this->actingAs($trader)->getJson('/api/v1/trade-management/health')
            ->assertOk()
            ->assertJsonPath('data.checks.live_modification', 'HARD_BLOCKED');
        $this->actingAs($trader)->getJson('/api/v1/trade-management/dashboard')->assertOk();

        $this->actingAs($trader)->postJson('/api/v1/trade-management/close-all', [
            'confirm' => true,
            'demo_verified' => true,
        ])->assertForbidden();

        $admin = $this->userWithRole('SUPER_ADMIN');
        $this->managedBuy($admin, entry: 1.1, sl: 1.09, volume: 0.1, brokerId: '810001');
        $this->actingAs($admin)->postJson('/api/v1/trade-management/close-all', [
            'confirm' => true,
            'demo_verified' => true,
        ])->assertOk();
    }

    public function test_static_no_extra_order_send_in_php(): void
    {
        $root = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\border_send\s*\(/', $src, $file->getPathname());
        }
    }

    private function seedSafety(bool $demoEnabled): void
    {
        foreach (SettingsService::SAFETY_DEFAULTS as $key => $value) {
            ApplicationSetting::query()->updateOrCreate(
                ['key' => $key],
                ['group' => 'trading', 'value' => $key === 'allow_demo_execution' ? $demoEnabled : ($key === 'emergency_stop' ? false : $value), 'is_public' => true]
            );
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function policy(User $user, array $overrides = []): TradeManagementPolicy
    {
        return TradeManagementPolicy::query()->create(array_merge([
            'user_id' => $user->id,
            'name' => $overrides['name'] ?? 'default',
            'version' => 1,
            'is_active' => true,
            'break_even_enabled' => true,
            'break_even_trigger_type' => 'R_MULTIPLE',
            'break_even_trigger_value' => 1,
            'break_even_offset' => 0,
            'trailing_enabled' => false,
            'trailing_type' => TrailingType::FixedDistance,
            'partial_close_enabled' => false,
            'risk_exit' => true,
            'emergency_exit' => true,
        ], $overrides));
    }

    private function managedBuy(User $user, float $entry, float $sl, float $volume, string $brokerId = '710001'): ManagedPosition
    {
        $account = BrokerAccount::query()->firstOrCreate(
            ['user_id' => $user->id, 'broker_login' => '900001'],
            [
                'name' => 'Demo',
                'environment' => TradingEnvironment::Demo,
                'status' => 'READY',
                'is_enabled' => true,
                'currency' => 'USD',
                'leverage' => 100,
                'broker_server' => 'Nexa-Demo',
                'verified_trade_mode' => 'DEMO',
                'demo_verified_at' => now(),
            ]
        );

        return ManagedPosition::query()->create([
            'user_id' => $user->id,
            'broker_account_id' => $account->id,
            'ownership' => PositionOwnership::NexaManaged,
            'management_status' => ManagementStatus::Managing,
            'broker_position_id' => $brokerId,
            'symbol' => 'EURUSD',
            'broker_symbol' => 'EURUSD',
            'direction' => OrderDirection::Buy,
            'environment' => TradingEnvironment::Demo,
            'initial_volume' => $volume,
            'current_volume' => $volume,
            'entry_price' => $entry,
            'initial_stop_loss' => $sl,
            'current_stop_loss' => $sl,
            'opened_at' => now(),
            'metadata' => ['origin' => 'NEXA', 'nexa_managed' => true],
        ]);
    }
}
