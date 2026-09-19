<?php

namespace Tests\Feature;

use App\Enums\StrategyLifecycleState;
use App\Governance\Support\GovernanceSafety;
use App\Models\GovernedStrategyVersion;
use App\Models\StrategyDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSixteenStrategyGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_constants(): void
    {
        $this->assertSame(0, GovernanceSafety::ORDER_SEND_CALL_SITES_IN_PHASE_16);
        $this->assertFalse(GovernanceSafety::AI_MAY_APPROVE);
        $this->assertFalse(GovernanceSafety::AI_MAY_DEPLOY);
        $this->assertFalse(GovernanceSafety::AI_MAY_CHANGE_ACTIVE_CONFIG);
        $this->assertFalse(GovernanceSafety::LIVE_AUTO_EXISTS);
        $this->assertFalse(GovernanceSafety::LIVE_DEPLOY_EXISTS);
        $this->assertSame(['DEMO_AUTO'], GovernanceSafety::ALLOWED_DEPLOY_TARGETS);
    }

    public function test_health_and_system_status(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/governance/health')
            ->assertOk()
            ->assertJsonPath('data.phase', 16)
            ->assertJsonPath('data.order_send_phase16', 0)
            ->assertJsonPath('data.ai_may_approve', false)
            ->assertJsonPath('data.live_auto_exists', false);

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.strategy_governance.phase', 16)
            ->assertJsonPath('data.strategy_governance.ai_may_deploy', false)
            ->assertJsonPath('data.strategy_governance.live_deploy_exists', false);
    }

    public function test_live_deploy_and_ai_approve_refused(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/governance/live-deploy', [])
            ->assertStatus(403)
            ->assertJsonPath('data.live_auto_exists', false);
        $this->actingAs($user)->postJson('/api/v1/governance/ai-approve', [])
            ->assertStatus(403)
            ->assertJsonPath('data.ai_may_approve', false);
    }

    public function test_ai_actor_cannot_register_or_approve(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'ema_trend',
            'semantic_version' => '1.0.0',
            'actor_type' => 'AI',
        ])->assertStatus(403);
    }

    public function test_lifecycle_illegal_jump_rejected(): void
    {
        $user = $this->userWithRole('TRADER');
        $version = $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'ema_trend',
            'semantic_version' => '1.0.0',
            'configuration' => ['fast' => 12],
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/transition", [
            'to' => 'DEPLOYED_DEMO',
        ])->assertStatus(422);
    }

    public function test_full_demo_promotion_two_step_flow(): void
    {
        $user = $this->userWithRole('TRADER');

        $version = $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'ema_trend',
            'semantic_version' => '1.2.0',
            'configuration' => ['fast' => 10, 'slow' => 30],
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $version['lifecycle_state']);
        $this->assertNotEmpty($version['code_hash']);
        $this->assertNotEmpty($version['config_hash']);

        $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/transition", [
            'to' => 'IN_REVIEW',
        ])->assertOk()->assertJsonPath('data.lifecycle_state', 'IN_REVIEW');

        $rc = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/release-candidates", [
            'title' => 'RC ema_trend 1.2.0',
        ])->assertCreated()->json('data');
        $this->assertSame('OPEN', $rc['status']);

        $pkg = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/evidence", [
            'evidence_label' => 'DEMO',
            'sample_count' => 5,
            'phase12_analytics_refs' => [['dataset' => 'demo-trades']],
            'metrics' => ['win_rate' => 0.4],
        ])->assertCreated()->json('data');
        $this->assertTrue($pkg['insufficient_samples']);
        $this->assertFalse($pkg['can_auto_approve']);

        $this->actingAs($user)->postJson('/api/v1/governance/policies', [
            'name' => 'Strict DEMO',
            'min_samples' => 30,
            'require_forward_validation' => false,
        ])->assertCreated();

        $decision = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/validate", [
            'evidence_package_public_id' => $pkg['public_id'],
        ])->assertOk()->json('data');
        $this->assertSame('INSUFFICIENT', $decision['decision']);

        // Two-step approve candidate
        $approval = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/approvals", [
            'action' => 'APPROVE_CANDIDATE',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$approval['approval']['public_id']}/step", [
            'step' => 1,
            'token' => $approval['tokens']['step1'],
            'nonce' => $approval['tokens']['step1_nonce'],
            'idempotency_key' => 'approve-step1-a',
        ])->assertOk()->assertJsonPath('data.completed', false);

        // Replay protection
        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$approval['approval']['public_id']}/step", [
            'step' => 1,
            'token' => $approval['tokens']['step1'],
            'nonce' => $approval['tokens']['step1_nonce'],
            'idempotency_key' => 'approve-step1-b',
        ])->assertStatus(422);

        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$approval['approval']['public_id']}/step", [
            'step' => 2,
            'token' => $approval['tokens']['step2'],
            'nonce' => $approval['tokens']['step2_nonce'],
            'idempotency_key' => 'approve-step2-a',
        ])->assertOk()->assertJsonPath('data.completed', true);

        $this->assertSame(
            StrategyLifecycleState::Approved,
            GovernedStrategyVersion::query()->where('public_id', $version['public_id'])->first()->lifecycle_state
        );

        // Promote DEMO
        $promote = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/approvals", [
            'action' => 'PROMOTE_DEMO',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$promote['approval']['public_id']}/step", [
            'step' => 1,
            'token' => $promote['tokens']['step1'],
            'nonce' => $promote['tokens']['step1_nonce'],
        ])->assertOk();

        $done = $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$promote['approval']['public_id']}/step", [
            'step' => 2,
            'token' => $promote['tokens']['step2'],
            'nonce' => $promote['tokens']['step2_nonce'],
        ])->assertOk()->json('data');

        $this->assertSame('DEMO_AUTO', $done['deployment']['target']);
        $this->assertSame('ACTIVE', $done['deployment']['status']);
        $this->assertTrue($done['deployment']['positions_preserved']);
        $this->assertNotEmpty($done['automation_profile']['public_id']);
        $this->assertSame(
            StrategyLifecycleState::DeployedDemo,
            GovernedStrategyVersion::query()->where('public_id', $version['public_id'])->first()->lifecycle_state
        );
    }

    public function test_rollback_preserves_positions_flag(): void
    {
        $user = $this->userWithRole('TRADER');
        $version = $this->seedDeployedVersion($user);

        $rb = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version->public_id}/approvals", [
            'action' => 'ROLLBACK',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$rb['approval']['public_id']}/step", [
            'step' => 1,
            'token' => $rb['tokens']['step1'],
            'nonce' => $rb['tokens']['step1_nonce'],
        ])->assertOk();

        $result = $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$rb['approval']['public_id']}/step", [
            'step' => 2,
            'token' => $rb['tokens']['step2'],
            'nonce' => $rb['tokens']['step2_nonce'],
        ])->assertOk()->json('data');

        $this->assertSame('ROLLED_BACK', $result['version']['lifecycle_state']);
        $dep = StrategyDeployment::query()->where('governed_strategy_version_id', $version->id)->latest('id')->first();
        $this->assertTrue($dep->positions_preserved);
        $this->assertTrue($dep->history_preserved);
    }

    public function test_lab_never_deploys_and_comparison_diff(): void
    {
        $user = $this->userWithRole('TRADER');
        $a = $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'ema_trend',
            'semantic_version' => '2.0.0',
            'configuration' => ['fast' => 8],
        ])->assertCreated()->json('data');
        $b = $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'ema_trend',
            'semantic_version' => '2.1.0',
            'configuration' => ['fast' => 14],
        ])->assertCreated()->json('data');

        $cmp = $this->actingAs($user)->postJson('/api/v1/governance/comparisons', [
            'left_public_id' => $a['public_id'],
            'right_public_id' => $b['public_id'],
        ])->assertCreated()->json('data');
        $this->assertTrue($cmp['summary']['config_changed']);

        $lab = $this->actingAs($user)->getJson('/api/v1/governance/lab')
            ->assertOk()
            ->assertJsonPath('data.can_deploy', false)
            ->assertJsonPath('data.mutates_active_config', false)
            ->assertJsonPath('data.live_auto_controls', false);

        $exp = $this->actingAs($user)->postJson('/api/v1/governance/lab/experiments', [
            'lab_mode' => 'ROBUSTNESS',
            'version_public_id' => $a['public_id'],
            'parameters' => ['shocks' => [1, 2]],
        ])->assertCreated()->json('data');
        $this->assertFalse($exp['can_deploy']);
        $this->assertFalse($exp['mutates_active_config']);
        $this->assertSame('COMPLETED', $exp['status']);

        $pf = $this->actingAs($user)->postJson('/api/v1/governance/portfolios', [
            'name' => 'Core',
            'members' => [
                ['strategy_key' => 'ema_trend', 'semantic_version' => '2.0.0', 'weight' => 1, 'priority' => 10],
                ['strategy_key' => 'ema_trend', 'semantic_version' => '2.1.0', 'weight' => 2, 'priority' => 10],
                ['strategy_key' => 'rsi_momentum', 'semantic_version' => '1.0.0', 'weight' => 1, 'priority' => 20],
            ],
        ])->assertCreated()->json('data');
        $this->assertCount(2, $pf['members']);
        $this->assertTrue($pf['conflict_resolution']['deterministic']);

        $cr = $this->actingAs($user)->postJson('/api/v1/governance/change-requests', [
            'request_type' => 'PARAM_CHANGE',
            'version_public_id' => $a['public_id'],
            'proposed_change' => ['fast' => 9],
            'actor_type' => 'AI',
        ])->assertCreated()->json('data');
        $this->assertFalse($cr['ai_may_apply']);
        $this->assertTrue($cr['requires_human_approval']);
    }

    public function test_lifecycle_doc_and_dashboard(): void
    {
        $user = $this->userWithRole('TRADER');
        $this->actingAs($user)->getJson('/api/v1/governance/lifecycle')
            ->assertOk()
            ->assertJsonPath('data.illegal_jumps_rejected', true);
        $this->actingAs($user)->getJson('/api/v1/governance/dashboard')
            ->assertOk()
            ->assertJsonPath('data.demo_only', true)
            ->assertJsonPath('data.live_auto_controls', false);
    }

    private function seedDeployedVersion(User $user): GovernedStrategyVersion
    {
        $version = $this->actingAs($user)->postJson('/api/v1/governance/versions', [
            'strategy_key' => 'rsi_momentum',
            'semantic_version' => '3.0.0',
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/transition", ['to' => 'IN_REVIEW'])->assertOk();
        $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/release-candidates", [])->assertCreated();

        $ap = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/approvals", [
            'action' => 'APPROVE_CANDIDATE',
        ])->json('data');
        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$ap['approval']['public_id']}/step", [
            'step' => 1, 'token' => $ap['tokens']['step1'], 'nonce' => $ap['tokens']['step1_nonce'],
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$ap['approval']['public_id']}/step", [
            'step' => 2, 'token' => $ap['tokens']['step2'], 'nonce' => $ap['tokens']['step2_nonce'],
        ])->assertOk();

        $pr = $this->actingAs($user)->postJson("/api/v1/governance/versions/{$version['public_id']}/approvals", [
            'action' => 'PROMOTE_DEMO',
        ])->json('data');
        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$pr['approval']['public_id']}/step", [
            'step' => 1, 'token' => $pr['tokens']['step1'], 'nonce' => $pr['tokens']['step1_nonce'],
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/v1/governance/approvals/{$pr['approval']['public_id']}/step", [
            'step' => 2, 'token' => $pr['tokens']['step2'], 'nonce' => $pr['tokens']['step2_nonce'],
        ])->assertOk();

        return GovernedStrategyVersion::query()->where('public_id', $version['public_id'])->firstOrFail();
    }
}
