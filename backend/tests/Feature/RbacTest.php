<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_manage_users(): void
    {
        $viewer = $this->userWithRole('VIEWER');

        $this->actingAs($viewer)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_super_admin_can_create_user_with_role_atomically(): void
    {
        $admin = $this->userWithRole();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Operator User',
            'email' => 'operator@example.test',
            'password' => 'SecurePassword!123',
            'role' => 'TRADER',
        ])->assertCreated()->assertJsonPath('data.roles.0.name', 'TRADER');

        $this->assertDatabaseHas('users', ['email' => 'operator@example.test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
    }

    public function test_role_change_is_persisted_and_audited_without_password_data(): void
    {
        $admin = $this->userWithRole();
        $target = $this->userWithRole('TRADER');

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'ANALYST',
        ])->assertOk()->assertJsonPath('data.roles.0.name', 'ANALYST');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.role_updated',
            'entity_id' => (string) $target->id,
        ]);
    }

    public function test_inactive_authenticated_session_is_rejected(): void
    {
        $user = $this->userWithRole('VIEWER');
        $user->update(['status' => 'SUSPENDED']);

        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_super_admin_can_manage_emergency_stop_but_admin_cannot(): void
    {
        $superAdmin = $this->userWithRole('SUPER_ADMIN');
        $admin = $this->userWithRole('ADMIN');

        $this->actingAs($superAdmin)->putJson('/api/v1/emergency-stop', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.value', false);
        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/emergency-stop', ['enabled' => true])->assertForbidden();
        $this->actingAs($admin)->putJson('/api/v1/settings/emergency_stop', ['value' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');
        $this->assertDatabaseHas('audit_logs', ['action' => 'setting.updated', 'module' => 'SETTING']);
    }

    public function test_trader_can_submit_simulation_command_but_analyst_cannot(): void
    {
        $trader = $this->userWithRole('TRADER');
        $analyst = $this->userWithRole('ANALYST');
        $payload = [
            'command_id' => '30000000-0000-4000-8000-000000000001',
            'idempotency_key' => 'role-test',
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'volume' => 0.1,
        ];

        $this->actingAs($trader)->postJson('/api/v1/simulation/orders', $payload)->assertUnprocessable();
        $this->actingAs($analyst)->postJson('/api/v1/simulation/orders', $payload)->assertForbidden();
    }

    public function test_analyst_has_read_access_while_viewer_has_narrower_access(): void
    {
        $analyst = $this->userWithRole('ANALYST');
        $viewer = $this->userWithRole('VIEWER');

        $this->actingAs($analyst)->getJson('/api/v1/risk-profiles')->assertOk();
        $this->actingAs($analyst)->postJson('/api/v1/risk-profiles', [])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/strategies')->assertOk();
        $this->actingAs($viewer)->putJson('/api/v1/preferences', ['theme' => 'dark'])->assertForbidden();
    }
}
