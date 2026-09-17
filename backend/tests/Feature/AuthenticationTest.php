<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_api_returns_json_unauthorized_without_login_route_redirect(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_active_user_can_login_read_session_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'SecurePassword!123', 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SecurePassword!123'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_suspended_and_disabled_users_cannot_login(): void
    {
        foreach (['SUSPENDED', 'DISABLED'] as $status) {
            $user = User::factory()->create([
                'email' => strtolower($status).'@example.test',
                'password' => 'SecurePassword!123',
                'status' => $status,
            ]);
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SecurePassword!123'])->assertForbidden();
        }
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_password_request_is_non_enumerating_and_does_not_claim_email_delivery(): void
    {
        $this->postJson('/api/v1/auth/password/request', ['email' => 'unknown@example.test'])
            ->assertAccepted()
            ->assertJsonPath('message', 'If the account exists, the reset request was registered. Token delivery is not configured.');
    }

    public function test_password_can_be_reset_with_valid_broker_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewSecurePassword!123',
            'password_confirmation' => 'NewSecurePassword!123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecurePassword!123', $user->fresh()->password));
    }
}
