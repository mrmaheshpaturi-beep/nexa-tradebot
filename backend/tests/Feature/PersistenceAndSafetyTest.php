<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrokerAccount;
use App\Models\RiskProfile;
use App\Models\TradingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PersistenceAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_relationships_persist(): void
    {
        $user = $this->userWithRole();
        $risk = RiskProfile::create(['user_id' => $user->id, 'name' => 'Safe']);
        $account = BrokerAccount::create(['user_id' => $user->id, 'risk_profile_id' => $risk->id, 'name' => 'Simulation']);
        $strategy = TradingStrategy::create([
            'user_id' => $user->id,
            'name' => 'Draft',
            'slug' => 'draft',
            'category' => 'CUSTOM',
            'symbols' => ['EURUSD'],
            'timeframes' => ['H1'],
        ]);
        $strategy->settings()->create(['key' => 'period', 'value' => ['minutes' => 15]]);

        $this->assertTrue($user->brokerAccounts->contains($account));
        $this->assertTrue($risk->brokerAccounts->contains($account));
        $this->assertSame(15, $strategy->settings->first()->value['minutes']);
    }

    public function test_broker_and_strategy_requests_cannot_enable_execution(): void
    {
        $user = $this->userWithRole();

        $this->actingAs($user)->postJson('/api/v1/broker-accounts', [
            'name' => 'Unsafe',
            'execution_enabled' => true,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/strategies', [
            'name' => 'Unsafe',
            'auto_trading_enabled' => true,
        ])->assertUnprocessable();
    }

    public function test_locked_execution_settings_cannot_be_enabled(): void
    {
        $user = $this->userWithRole();

        $this->actingAs($user)->putJson('/api/v1/settings/allow_live_execution', ['value' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = AuditLog::create([
            'action' => 'test',
            'module' => 'TEST',
            'description' => 'Immutable test.',
        ]);
        $this->expectException(LogicException::class);
        $log->update(['action' => 'changed']);
    }
}
