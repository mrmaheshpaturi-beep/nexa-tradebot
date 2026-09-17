<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use App\Models\Notification;
use App\Models\Role;
use App\Models\ServiceHeartbeat;
use App\Models\Signal;
use App\Models\TradingInstrument;
use App\Models\TradingTerminal;
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
        $instrument = TradingInstrument::firstOrCreate(['symbol' => 'EURUSD'], [
            'name' => 'Euro / US Dollar',
            'display_name' => 'Euro / US Dollar',
            'asset_class' => 'FOREX',
            'currency_base' => 'EUR',
            'currency_quote' => 'USD',
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'digits' => 5,
            'point_size' => 0.00001,
            'contract_size' => 100000,
            'tick_size' => 0.00001,
            'tick_value' => 1,
            'volume_min' => 0.01,
            'volume_max' => 5,
            'volume_step' => 0.01,
            'minimum_volume' => 0.01,
            'maximum_volume' => 5,
            'step_volume' => 0.01,
            'minimum_stop_distance' => 0,
            'margin_rate' => 1,
            'is_enabled' => true,
        ]);
        foreach ([
            ['XAUUSD', 'Gold / US Dollar', 'METAL', 'XAU', 'USD', 2, 0.01, 100, 0.01, 5, 0.01],
            ['GBPUSD', 'British Pound / US Dollar', 'FOREX', 'GBP', 'USD', 5, 0.00001, 100000, 0.01, 5, 0.01],
            ['USDJPY', 'US Dollar / Japanese Yen', 'FOREX', 'USD', 'JPY', 3, 0.001, 100000, 0.01, 5, 0.01],
            ['NAS100', 'Nasdaq 100', 'INDEX', 'NAS', 'USD', 2, 0.01, 1, 0.01, 5, 0.01],
            ['BTCUSD', 'Bitcoin / US Dollar', 'CRYPTO', 'BTC', 'USD', 2, 0.01, 1, 0.01, 5, 0.01],
        ] as [$symbol, $name, $assetClass, $base, $quote, $digits, $point, $contract, $minimum, $maximum, $step]) {
            TradingInstrument::firstOrCreate(['symbol' => $symbol], [
                'name' => $name,
                'display_name' => $name,
                'asset_class' => $assetClass,
                'currency_base' => $base,
                'currency_quote' => $quote,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'digits' => $digits,
                'point_size' => $point,
                'contract_size' => $contract,
                'tick_size' => $point,
                'tick_value' => 1,
                'volume_min' => $minimum,
                'volume_max' => $maximum,
                'volume_step' => $step,
                'minimum_volume' => $minimum,
                'maximum_volume' => $maximum,
                'step_volume' => $step,
                'minimum_stop_distance' => 0,
                'margin_rate' => 1,
                'is_enabled' => true,
            ]);
        }
        $terminal = TradingTerminal::firstOrCreate(['name' => 'Offline Simulation Terminal'], [
            'broker_account_id' => $account->id,
            'platform' => 'SIMULATION',
            'machine_identifier' => 'local-simulation',
            'environment' => 'SIMULATION',
            'status' => 'OFFLINE',
            'adapter' => 'SIMULATION',
            'version' => '3.0',
            'metadata' => ['broker_connectivity' => false],
        ]);
        ServiceHeartbeat::firstOrCreate([
            'service' => 'SIMULATION_ENGINE',
            'instance_id' => 'simulation-engine-local',
            'environment' => 'SIMULATION',
        ], [
            'trading_terminal_id' => $terminal->id,
            'status' => 'OFFLINE',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => ['reason' => 'Safety defaults active'],
            'metadata' => ['broker_transmission' => false],
        ]);
        Signal::firstOrCreate([
            'user_id' => $user->id,
            'source' => 'MOCK',
            'symbol' => 'EURUSD',
        ], [
            'trading_strategy_id' => $strategy->id,
            'broker_account_id' => $account->id,
            'trading_instrument_id' => $instrument->id,
            'direction' => 'BUY',
            'timeframe' => 'H1',
            'score' => 0.8,
            'entry_price' => 1.1002,
            'entry_reference' => 1.1002,
            'stop_loss' => 1.0952,
            'take_profit_1' => 1.1102,
            'take_profit_1_reference' => 1.1102,
            'risk_reward' => 2,
            'status' => 'GENERATED',
            'environment' => 'SIMULATION',
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
            'explanation' => 'Deterministic mock signal for the simulation lifecycle.',
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
