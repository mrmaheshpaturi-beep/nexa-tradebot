<?php

namespace Tests\Feature;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningQueueJob;
use App\Models\HardeningWorkerProcess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseNineteenProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_matrix_and_forbidden_modes(): void
    {
        $matrix = HardeningSafety::matrix();
        $this->assertSame(19, $matrix['phase']);
        $this->assertSame(0, $matrix['order_send_phase19']);
        $this->assertSame(0, $matrix['ai_execution']);
        $this->assertFalse($matrix['live_auto_exists']);
        $this->assertSame('RECONCILE_NOT_RETRY', $matrix['unknown_execution_policy']);
        $this->assertSame('NONE', $matrix['blind_retry_on_unknown']);
        $this->assertTrue($matrix['phase_9_risk_mandatory']);
        $this->assertTrue($matrix['phase_10_sole_execution']);
        $this->assertTrue($matrix['phase_16_governance_intact']);
        $this->assertTrue($matrix['phase_18_isolation_intact']);

        $this->expectException(\InvalidArgumentException::class);
        HardeningSafety::assertBrokerTradeMode('LIVE');
    }

    public function test_system_status_exposes_phase_nineteen(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.production_hardening.phase', 19)
            ->assertJsonPath('data.production_hardening.order_send_phase19', 0)
            ->assertJsonPath('data.production_hardening.live_auto_exists', false)
            ->assertJsonPath('data.production_hardening.unknown_execution_policy', 'RECONCILE_NOT_RETRY');
    }

    public function test_ops_control_center_separates_readiness(): void
    {
        $user = $this->userWithRole('TRADER');
        $ops = $this->actingAs($user)->getJson('/api/v1/hardening/ops')->assertOk()->json('data');
        $this->assertSame(19, $ops['phase']);
        $this->assertArrayHasKey('liveness', $ops);
        $this->assertArrayHasKey('readiness', $ops);
        $this->assertArrayHasKey('trading_readiness', $ops);
        $this->assertSame('ALIVE', $ops['liveness']['status']);
        $this->assertSame('NOT_READY', $ops['trading_readiness']['live']);
        $this->assertSame('DOES_NOT_EXIST', $ops['trading_readiness']['live_auto']);

        $sep = $this->actingAs($user)->getJson('/api/v1/hardening/trading-readiness')->assertOk()->json('data');
        $this->assertSame('ALIVE', $sep['liveness']);
        $this->assertSame('NOT_READY', $sep['live']);
        $this->assertSame('DOES_NOT_EXIST', $sep['live_auto']);
    }

    public function test_app_vs_broker_env_validation_and_live_block(): void
    {
        $user = $this->userWithRole('TRADER');
        $ok = $this->actingAs($user)->postJson('/api/v1/hardening/environments/validate', [
            'app_environment' => 'LOCAL',
            'broker_trade_mode' => 'SIMULATION',
        ])->assertOk()->json('data');
        $this->assertTrue($ok['ok']);

        $bad = $this->actingAs($user)->postJson('/api/v1/hardening/environments/validate', [
            'app_environment' => 'LOCAL',
            'broker_trade_mode' => 'LIVE',
        ])->assertOk()->json('data');
        $this->assertFalse($bad['ok']);
        $this->assertNotEmpty($bad['errors']);
    }

    public function test_secret_inventory_never_returns_values(): void
    {
        $user = $this->userWithRole('TRADER');
        $data = $this->actingAs($user)->getJson('/api/v1/hardening/secrets')->assertOk()->json('data');
        $this->assertSame('FORBIDDEN', $data['frontend_secrets']);
        $this->assertSame('FORBIDDEN', $data['git_secrets']);
        foreach ($data['items'] as $item) {
            $this->assertSame('[NEVER_RETURNED]', $item['value']);
            $this->assertTrue($item['frontend_forbidden']);
            $this->assertTrue($item['git_forbidden']);
        }

        $rotated = $this->actingAs($user)->postJson('/api/v1/hardening/secrets/rotate', [
            'secret_key' => 'APP_KEY',
        ])->assertOk()->json('data');
        $this->assertSame('[NEVER_RETURNED]', $rotated['value']);
    }

    public function test_security_headers_present_on_api(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_idempotent_queue_and_dlq(): void
    {
        $user = $this->userWithRole('TRADER');
        $a = $this->actingAs($user)->postJson('/api/v1/hardening/queues', [
            'queue_name' => 'SAFETY',
            'job_type' => 'TEST_JOB',
            'idempotency_key' => 'idem-1',
            'payload' => ['x' => 1],
        ])->assertCreated()->json('data');

        $b = $this->actingAs($user)->postJson('/api/v1/hardening/queues', [
            'queue_name' => 'SAFETY',
            'job_type' => 'TEST_JOB',
            'idempotency_key' => 'idem-1',
            'payload' => ['x' => 2],
        ])->assertCreated()->json('data');

        $this->assertSame($a['public_id'], $b['public_id']);

        $job = HardeningQueueJob::query()->where('public_id', $a['public_id'])->firstOrFail();
        $job->forceFill(['attempts' => 2, 'max_attempts' => 3, 'status' => 'RUNNING'])->save();
        app(\App\Hardening\Queues\HardeningJobQueue::class)->complete($job, false, 'boom');
        $this->assertSame('DEAD', $job->fresh()->status);
        $this->assertDatabaseCount('hardening_dlq_jobs', 1);
    }

    public function test_worker_graceful_shutdown_and_restart_reconcile(): void
    {
        $user = $this->userWithRole('TRADER');
        $worker = $this->actingAs($user)->postJson('/api/v1/hardening/workers', [
            'worker_kind' => 'PYTHON_BRIDGE',
            'label' => 'bridge-1',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson('/api/v1/hardening/workers/'.$worker['public_id'], [
            'action' => 'START',
        ])->assertOk()->assertJsonPath('data.restart_reconcile_required', true)
            ->assertJsonPath('data.reconcile_completed', false);

        $this->actingAs($user)->postJson('/api/v1/hardening/workers/'.$worker['public_id'], [
            'action' => 'SHUTDOWN',
        ])->assertOk()->assertJsonPath('data.graceful_shutdown', true);

        $this->actingAs($user)->postJson('/api/v1/hardening/workers/'.$worker['public_id'], [
            'action' => 'STOP',
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/v1/hardening/workers/'.$worker['public_id'], [
            'action' => 'START',
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/v1/hardening/workers/'.$worker['public_id'], [
            'action' => 'RECONCILE',
        ])->assertOk()
            ->assertJsonPath('data.reconcile_completed', true)
            ->assertJsonPath('data.restart_reconcile_required', false);

        $row = HardeningWorkerProcess::query()->where('public_id', $worker['public_id'])->firstOrFail();
        $this->assertSame('NONE', $row->metadata['blind_retry'] ?? null);
    }

    public function test_deploy_requires_reconcile_before_resume(): void
    {
        $user = $this->userWithRole('TRADER');
        $deploy = $this->actingAs($user)->postJson('/api/v1/hardening/deploy', [
            'version' => '1.19.0',
            'git_sha' => 'abc123',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson('/api/v1/hardening/deploy/'.$deploy['public_id'], [
            'action' => 'MAINTENANCE',
        ])->assertOk()->assertJsonPath('data.trading_paused', true);

        $this->actingAs($user)->postJson('/api/v1/hardening/deploy/'.$deploy['public_id'], [
            'action' => 'ACTIVATE',
        ])->assertOk()->assertJsonPath('data.trading_paused', true)
            ->assertJsonPath('data.reconcile_before_resume', true);

        $this->actingAs($user)->postJson('/api/v1/hardening/deploy/'.$deploy['public_id'], [
            'action' => 'RESUME',
        ])->assertOk()->assertJsonPath('data.trading_paused', false);
    }

    public function test_scoped_safe_modes(): void
    {
        $user = $this->userWithRole('TRADER');
        $row = $this->actingAs($user)->postJson('/api/v1/hardening/safe-modes', [
            'scope' => 'ACCOUNT',
            'scope_ref' => 'acc-1',
            'reason' => 'fingerprint mismatch drill',
        ])->assertCreated()->json('data');

        $this->assertTrue($row['active']);
        $this->actingAs($user)->postJson('/api/v1/hardening/safe-modes/'.$row['public_id'].'/clear')
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }

    public function test_isolated_restore_and_dr_checklist(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/hardening/dr')
            ->assertOk()
            ->assertJsonPath('data.durability.auto_resume', 'FORBIDDEN')
            ->assertJsonPath('data.checklist.blind_retry', 'NONE');

        $restore = $this->actingAs($user)->postJson('/api/v1/hardening/isolated-restore', [])
            ->assertCreated()
            ->json('data');
        $this->assertTrue($restore['isolated']);
        $this->assertTrue($restore['reconcile_required_before_trading']);
        $this->assertTrue($restore['auto_resume_forbidden']);
    }

    public function test_soak_chaos_ci_safe_not_multi_day_claim(): void
    {
        $user = $this->userWithRole('TRADER');
        $posture = $this->actingAs($user)->getJson('/api/v1/hardening/soak')->assertOk()->json('data');
        $this->assertSame('NOT_CLAIMED', $posture['multi_day_soak_in_ci']);
        $this->assertTrue($posture['live_forbidden']);

        $soak = $this->actingAs($user)->postJson('/api/v1/hardening/soak/run')->assertCreated()->json('data');
        $this->assertFalse($soak['multi_day_soak_executed']);
        $this->assertFalse($soak['against_live']);

        $chaos = $this->actingAs($user)->postJson('/api/v1/hardening/chaos/run', [
            'scenario' => 'unknown_execution_state',
        ])->assertCreated()->json('data');
        $this->assertFalse($chaos['against_live']);
        $this->assertFalse($chaos['order_send']);
    }

    public function test_ai_mutation_and_live_auto_refused(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/hardening/ai-mutate')
            ->assertStatus(403)
            ->assertJsonPath('data.refused', true);
        $this->actingAs($user)->postJson('/api/v1/hardening/live-auto')
            ->assertStatus(403)
            ->assertJsonPath('data.refused', true);
    }

    public function test_mfa_foundation_challenge_verify(): void
    {
        $user = $this->userWithRole('TRADER');
        $svc = app(\App\Hardening\Identity\IdentityHardeningService::class);
        $challenge = $svc->issueMfaChallenge($user, 'SENSITIVE_OPS');
        $this->assertNotNull($challenge['_test_code']);

        $this->assertTrue($svc->verifyMfaChallenge(
            $challenge['public_id'],
            $challenge['_test_code'],
            $challenge['nonce'],
        ));
        $this->assertFalse($svc->verifyMfaChallenge(
            $challenge['public_id'],
            $challenge['_test_code'],
            $challenge['nonce'],
        ));
    }

    public function test_service_and_node_identity(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/hardening/identity/services', [
            'service_name' => 'bridge-worker',
        ])->assertCreated()->assertJsonPath('data.claims.may_execute', false);

        $this->actingAs($user)->postJson('/api/v1/hardening/identity/nodes', [
            'node_label' => 'win-node-1',
            'platform' => 'WINDOWS',
        ])->assertCreated()->assertJsonPath('data.tls_required', true);

        $this->actingAs($user)->getJson('/api/v1/hardening/windows')
            ->assertOk()
            ->assertJsonPath('data.status', 'CHECKLIST_ONLY_PENDING_MANUAL');
    }

    public function test_dependency_audit_and_capacity(): void
    {
        $user = $this->userWithRole('TRADER');
        $deps = $this->actingAs($user)->getJson('/api/v1/hardening/dependencies')->assertOk()->json('data');
        $this->assertTrue($deps['ok']);
        $this->assertTrue($deps['posture']['npm_lockfile']);

        $this->actingAs($user)->getJson('/api/v1/hardening/capacity')
            ->assertOk()
            ->assertJsonPath('data.live_capacity_planning', 'NOT_APPLICABLE');
    }

    public function test_viewer_cannot_operate_hardening(): void
    {
        $viewer = $this->userWithRole('VIEWER');
        $this->actingAs($viewer)->postJson('/api/v1/hardening/safe-modes', [
            'scope' => 'GLOBAL',
            'reason' => 'nope',
        ])->assertStatus(403);
    }

    public function test_failover_extends_phase_eighteen_leases(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/hardening/failover')
            ->assertOk()
            ->assertJsonPath('data.extends_phase_18_leases', true)
            ->assertJsonPath('data.blind_retry', 'NONE');
    }
}
