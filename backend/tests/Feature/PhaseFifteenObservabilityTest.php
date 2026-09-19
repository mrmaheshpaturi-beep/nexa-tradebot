<?php

namespace Tests\Feature;

use App\Enums\CircuitBreakerState;
use App\Models\ServiceHeartbeat;
use App\Models\User;
use App\Observability\SecretRedactor;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseFifteenObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_constants_and_forbidden_environments(): void
    {
        $this->assertSame(0, ObservabilitySafety::ORDER_SEND_CALL_SITES_IN_PHASE_15);
        $this->assertSame(0, ObservabilitySafety::AI_EXECUTION_CALL_SITES);
        $this->assertFalse(ObservabilitySafety::LIVE_AUTO_EXISTS);
        $this->assertContains('DEMO_VPS', ObservabilitySafety::ALLOWED_ENVIRONMENTS);
        $this->assertNotContains('LIVE_PRODUCTION', ObservabilitySafety::ALLOWED_ENVIRONMENTS);

        $this->expectException(\InvalidArgumentException::class);
        ObservabilitySafety::assertEnvironmentAllowed('LIVE_PRODUCTION');
    }

    public function test_public_health_endpoints(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 15)
            ->assertJsonPath('data.order_send_phase15', 0)
            ->assertJsonPath('data.live_auto_exists', false);

        $this->getJson('/api/v1/health/liveness')
            ->assertOk()
            ->assertJsonPath('data.status', 'ALIVE');

        $this->getJson('/api/v1/health/readiness')->assertOk();

        $ready = $this->getJson('/api/v1/health/trading-readiness')->assertOk()->json('data');
        $this->assertSame('NOT_READY', $ready['live']);
        $this->assertSame('DOES_NOT_EXIST', $ready['live_auto']);
    }

    public function test_system_status_exposes_phase_fifteen(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.observability.phase', 15)
            ->assertJsonPath('data.observability.live_auto_exists', false)
            ->assertJsonPath('data.observability.order_send_phase15', 0)
            ->assertJsonPath('data.allow_live_execution', false);
    }

    public function test_metrics_evidence_labels_separate_and_insufficient_sample_warning(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/observability/metrics', [
            'name' => 'demo_win_rate',
            'value' => 0.55,
            'category' => 'business_demo',
            'evidence_label' => 'DEMO',
            'sample_count' => 5,
        ])->assertCreated()->assertJsonPath('data.insufficient_sample', true);

        $this->actingAs($user)->postJson('/api/v1/observability/metrics', [
            'name' => 'backtest_win_rate',
            'value' => 0.7,
            'category' => 'business_demo',
            'evidence_label' => 'BACKTEST',
            'sample_count' => 100,
        ])->assertCreated()->assertJsonPath('data.insufficient_sample', false);

        $summary = $this->actingAs($user)->getJson('/api/v1/observability/metrics')->assertOk()->json('data');
        $this->assertTrue($summary['evidence_labels_separate']);
        $this->assertArrayHasKey('DEMO', $summary['by_label']);
        $this->assertArrayHasKey('BACKTEST', $summary['by_label']);

        $this->actingAs($user)->postJson('/api/v1/observability/metrics', [
            'name' => 'mixed',
            'value' => 1,
            'category' => 'business_demo',
            'evidence_label' => 'LIVE',
        ])->assertStatus(422);
    }

    public function test_alert_dedup_ack_and_providers(): void
    {
        $user = $this->userWithRole('TRADER');
        $a = $this->actingAs($user)->postJson('/api/v1/observability/alerts', [
            'category' => 'OPS',
            'title' => 'Dedup me',
            'severity' => 'WARNING',
        ])->assertCreated()->json('data');

        $b = $this->actingAs($user)->postJson('/api/v1/observability/alerts', [
            'category' => 'OPS',
            'title' => 'Dedup me',
            'severity' => 'WARNING',
        ])->assertCreated()->json('data');

        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(2, $b['occurrence_count']);

        $center = $this->actingAs($user)->getJson('/api/v1/observability/alerts')->assertOk()->json('data');
        $this->assertNotEmpty($center['providers']);
        $this->assertTrue(collect($center['providers'])->contains(fn ($p) => $p['name'] === 'IN_APP' && $p['configured'] === true));

        $this->actingAs($user)->postJson('/api/v1/observability/alerts/'.$a['public_id'].'/ack')
            ->assertOk()
            ->assertJsonPath('data.status', 'ACKED');
    }

    public function test_forward_validation_distinct_from_backtest(): void
    {
        $user = $this->userWithRole('TRADER');
        $session = $this->actingAs($user)->postJson('/api/v1/observability/validation-sessions', [
            'mode' => 'DEMO_FORWARD',
        ])->assertCreated()->json('data');

        $this->assertFalse($session['is_backtest']);
        $this->assertSame('DEMO', $session['evidence_label']);

        $obs = $this->actingAs($user)->postJson('/api/v1/observability/validation-sessions/'.$session['public_id'].'/observe', [
            'stage' => 'scanned',
            'outcome' => 'PASS',
            'symbol' => 'EURUSD',
        ])->assertCreated()->json('data');

        $this->assertSame(1, $obs['session']['funnel']['scanned']);

        $this->actingAs($user)->postJson('/api/v1/observability/validation-sessions/'.$session['public_id'].'/observe', [
            'stage' => 'rejected',
            'outcome' => 'REJECT',
            'payload' => ['reason' => 'SPREAD'],
            'research_only' => true,
        ])->assertCreated();

        $lab = $this->actingAs($user)->getJson('/api/v1/observability/validation-lab')->assertOk()->json('data');
        $this->assertTrue($lab['forward_testing_distinct_from_backtest']);
    }

    public function test_bad_data_blocks_new_trades_and_clock_drift(): void
    {
        $user = $this->userWithRole('TRADER');
        $row = $this->actingAs($user)->postJson('/api/v1/observability/data-quality', [
            'source' => 'MARKET_DATA',
            'score' => 0.2,
            'symbol' => 'EURUSD',
            'clock_drift_ms' => 9000,
        ])->assertCreated()->json('data');

        $this->assertTrue($row['blocks_new_trades']);
        $this->assertSame('CLOCK_DRIFT', $row['verdict']);

        $dq = $this->actingAs($user)->getJson('/api/v1/observability/data-quality')->assertOk()->json('data');
        $this->assertTrue($dq['blocks_new_trades']);
    }

    public function test_drift_never_auto_disables_on_noise_but_safety_block_ok(): void
    {
        $user = $this->userWithRole('TRADER');
        $noise = $this->actingAs($user)->postJson('/api/v1/observability/drift', [
            'strategy_key' => 'ema_trend',
            'evidence_label' => 'DEMO',
            'metrics' => ['sample_count' => 3, 'drift_pct' => 2],
            'safety_issue' => false,
        ])->assertCreated()->json('data');
        $this->assertFalse($noise['auto_disable']);
        $this->assertFalse($noise['safety_block']);

        $safety = $this->actingAs($user)->postJson('/api/v1/observability/drift', [
            'strategy_key' => 'ema_trend',
            'evidence_label' => 'DEMO',
            'metrics' => ['sample_count' => 100, 'drift_pct' => 40],
            'safety_issue' => true,
        ])->assertCreated()->json('data');
        $this->assertFalse($safety['auto_disable']);
        $this->assertTrue($safety['safety_block']);
    }

    public function test_stale_heartbeat_blocks_new_entries_via_watchdog(): void
    {
        ServiceHeartbeat::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'service' => 'MARKET_DATA',
            'instance_id' => 'test-stale',
            'status' => 'ONLINE',
            'environment' => 'SIMULATION',
            'observed_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinutes(10),
            'metadata' => [],
        ]);

        $user = $this->userWithRole('TRADER');
        $watch = $this->actingAs($user)->postJson('/api/v1/observability/watchdog')->assertOk()->json('data');
        $this->assertFalse($watch['duplicates_orders']);
        $this->assertTrue($watch['new_entries_blocked']);
        $this->assertContains($watch['action'], ['PAUSE_NEW_ENTRIES', 'SAFE_MODE', 'DEGRADE', 'ALERT']);
    }

    public function test_circuit_breaker_and_unknown_execution_reconcile(): void
    {
        $user = $this->userWithRole('TRADER');
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/v1/observability/circuits/event', [
                'name' => 'broker',
                'event' => 'failure',
            ])->assertOk();
        }
        $status = $this->actingAs($user)->getJson('/api/v1/observability/circuits')->assertOk()->json('data');
        $broker = collect($status)->firstWhere('name', 'broker');
        $this->assertSame(CircuitBreakerState::Open->value, $broker['state']);

        $recon = $this->actingAs($user)->getJson('/api/v1/observability/reconciliation')->assertOk()->json('data');
        $this->assertSame('RECONCILE', $recon['decision_unknown']);
        $this->assertSame('RECONCILE_NOT_RETRY', $recon['unknown_execution_policy']);
    }

    public function test_backup_verify_and_disaster_recovery(): void
    {
        $user = $this->userWithRole('TRADER');
        $dir = storage_path('framework/testing/backups-'.uniqid());
        // Use service directly for custom dir — API uses default storage
        $run = app(\App\Observability\BackupService::class)->run($dir);
        $this->assertTrue($run->verified);
        $this->assertTrue($run->restore_tested);
        $this->assertTrue($run->reconcile_required_before_trading);

        $dr = $this->actingAs($user)->getJson('/api/v1/observability/disaster-recovery')->assertOk()->json('data');
        $this->assertTrue($dr['reconcile_before_new_trading']);
        $this->assertSame('DOES_NOT_EXIST', $dr['live_production']);
    }

    public function test_live_production_refused_and_scorecard_not_profit(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/observability/live-production')
            ->assertStatus(403)
            ->assertJsonPath('data.live_production', false);

        $card = $this->actingAs($user)->getJson('/api/v1/observability/scorecard')->assertOk()->json('data');
        $this->assertSame('OPERATIONAL_NOT_STRATEGY_PROFIT', $card['type']);
        $this->assertSame('NO_AUTOMATIC_DECLARATION', $card['safe_for_real_money']);
    }

    public function test_secret_redaction(): void
    {
        $redactor = new SecretRedactor;
        $out = $redactor->redact([
            'password' => 'secret123',
            'mt5_password' => 'x',
            'ok' => 'visible',
            'nested' => ['api_key' => 'abc', 'name' => 'nexa'],
        ]);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['mt5_password']);
        $this->assertSame('visible', $out['ok']);
        $this->assertSame('[REDACTED]', $out['nested']['api_key']);
        $this->assertSame('nexa', $out['nested']['name']);
    }

    public function test_failure_injection_and_ci_soak_not_days(): void
    {
        $harness = app(\App\Observability\FailureInjectionHarness::class);
        $this->assertContains('unknown_execution_state', $harness->scenarios());
        $result = $harness->run('unknown_execution_state', fn () => 'ok');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['live']);

        $soak = app(\App\Observability\SoakTestFramework::class);
        $manual = $soak->plan(86400, 'MANUAL_SOAK_DAYS');
        $this->assertTrue($manual['manual_only']);
        $this->assertFalse($manual['execute_in_ci']);
        $this->assertFalse($manual['windows_reboot_claimed_executed']);

        $ci = $soak->runCiShort(fn ($i) => $i, 3);
        $this->assertSame(3, $ci['ticks_executed']);
        $this->assertTrue($ci['plan']['ci_safe']);
    }

    public function test_operations_dashboard_rbac(): void
    {
        $viewer = $this->userWithRole('VIEWER');
        $this->actingAs($viewer)->getJson('/api/v1/observability/operations')->assertOk();

        $none = User::factory()->create();
        // user without observability.view may still have trading.read via role — create bare user
        $this->actingAs($none)->getJson('/api/v1/observability/operations')->assertStatus(403);
    }
}
