<?php

namespace Tests;

use App\Models\Role;
use App\Models\TradingInstrument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function userWithRole(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $role = strtoupper(str_replace('-', '_', $role));
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    protected function seedInstruments(): void
    {
        foreach ([
            ['EURUSD', 'Euro / US Dollar', 'FOREX', 'EUR', 'USD', 5, 0.00001],
            ['XAUUSD', 'Gold / US Dollar', 'METAL', 'XAU', 'USD', 2, 0.01],
        ] as [$symbol, $name, $asset, $base, $quote, $digits, $point]) {
            TradingInstrument::query()->firstOrCreate(['symbol' => $symbol], [
                'name' => $name,
                'display_name' => $name,
                'asset_class' => $asset,
                'currency_base' => $base,
                'currency_quote' => $quote,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'digits' => $digits,
                'point_size' => $point,
                'contract_size' => 100000,
                'tick_size' => $point,
                'tick_value' => 1,
                'volume_min' => 0.01,
                'volume_max' => 100,
                'volume_step' => 0.01,
                'minimum_volume' => 0.01,
                'maximum_volume' => 100,
                'step_volume' => 0.01,
                'is_enabled' => true,
            ]);
        }
    }
}
