<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = env('DEV_SUPER_ADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEV_SUPER_ADMIN_PASSWORD must be set to at least 12 characters before development seeding.');
        }

        $this->call(RolePermissionSeeder::class);

        $user = User::updateOrCreate(
            ['email' => 'admin@nexa.local'],
            ['name' => 'Nexa Development Admin', 'password' => $password, 'status' => 'ACTIVE'],
        );
        $user->roles()->sync([Role::where('name', 'SUPER_ADMIN')->value('id')]);
        $user->preference()->firstOrCreate([], ['timezone' => 'UTC', 'locale' => 'en']);

        foreach ([
            'general' => SettingsService::GENERAL_DEFAULTS,
            'trading' => SettingsService::SAFETY_DEFAULTS,
            'system' => SettingsService::SYSTEM_DEFAULTS,
        ] as $group => $settings) {
            foreach ($settings as $key => $value) {
                ApplicationSetting::updateOrCreate(['key' => $key], [
                    'group' => $group, 'value' => $value, 'is_public' => true, 'updated_by' => $user->id,
                ]);
            }
        }

        $risk = $user->riskProfiles()->firstOrCreate(['name' => 'Conservative Simulation'], [
            'status' => 'ACTIVE', 'is_default' => true, 'created_by' => $user->id, 'updated_by' => $user->id,
            'max_risk_per_trade' => 1, 'max_lot_size' => 1, 'max_daily_loss' => 4, 'max_weekly_loss' => 8,
            'max_drawdown' => 12, 'max_open_positions' => 8, 'max_open_risk' => 6, 'max_trades_per_day' => 20,
            'max_consecutive_losses' => 4, 'min_margin_level' => 300, 'max_spread' => 3,
            'max_slippage' => 1.5, 'min_reward_risk' => 1.5,
        ]);
        $account = $user->brokerAccounts()->firstOrCreate(['name' => 'Development Simulation'], [
            'risk_profile_id' => $risk->id, 'broker' => 'No broker - metadata only', 'platform' => 'NONE',
            'environment' => 'SIMULATION', 'status' => 'DISCONNECTED', 'is_enabled' => false,
            'currency' => 'USD', 'leverage' => 1, 'created_by' => $user->id,
        ]);
        $account->snapshots()->firstOrCreate(['captured_at' => now()->startOfMinute()], [
            'balance' => 10000, 'equity' => 10000, 'margin' => 0, 'free_margin' => 10000,
            'margin_level' => null, 'floating_pnl' => 0, 'drawdown' => 0,
        ]);
        $strategy = $user->strategies()->firstOrCreate(['name' => 'Safe Mock Strategy'], [
            'risk_profile_id' => $risk->id, 'slug' => 'safe-mock-strategy', 'category' => 'CUSTOM',
            'description' => 'Development-only simulation strategy.', 'status' => 'DRAFT', 'mode' => 'MANUAL',
            'version' => 1, 'minimum_signal_score' => 0.70, 'symbols' => ['EURUSD'],
            'timeframes' => ['H1'], 'sessions' => ['LONDON'], 'parameters' => ['source' => 'MOCK MARKET DATA'],
            'enabled' => false, 'auto_trading_enabled' => false, 'created_by' => $user->id,
        ]);
        $strategy->versions()->firstOrCreate(['version' => 1], [
            'created_by' => $user->id, 'configuration' => ['source' => 'MOCK MARKET DATA'],
            'change_summary' => 'Safe development seed.',
        ]);
        Notification::firstOrCreate(['user_id' => $user->id, 'type' => 'safety'], [
            'category' => 'SYSTEM',
            'severity' => 'CRITICAL',
            'title' => 'Simulation safety enabled',
            'message' => 'Emergency stop is active. No broker execution is available.',
            'data' => ['environment' => 'SIMULATION'],
        ]);
    }
}
