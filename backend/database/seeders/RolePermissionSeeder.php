<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard.view',
        'users.view', 'users.create', 'users.update', 'users.status',
        'strategies.view', 'strategies.create', 'strategies.update',
        'risk_profiles.view', 'risk_profiles.create', 'risk_profiles.update',
        'risk_engine.view', 'risk_engine.evaluate', 'risk_engine.lock',
        'broker_accounts.view', 'broker_accounts.create', 'broker_accounts.update',
        'settings.view', 'settings.update', 'emergency_stop.manage',
        'preferences.view', 'preferences.update',
        'notifications.view', 'notifications.update',
        'audit_logs.view', 'simulation_orders.create',
        'trading.read', 'signals.view', 'simulation_lifecycle.create',
        'simulation_lifecycle.evaluate', 'simulation_lifecycle.execute',
        'simulation_orders.cancel', 'simulation_positions.manage',
        'mt5.read', 'mt5.sync', 'mt5.reconcile', 'mt5.connections.manage',
        'market.configure',
        'execution.view', 'execution.confirm', 'execution.execute', 'execution.recover', 'execution.reconcile',
        'trade_management.view', 'trade_management.manage', 'trade_management.recover', 'trade_management.close_all',
        'analytics.view', 'analytics.manage', 'analytics.export',
        'backtest.view', 'backtest.run', 'backtest.export',
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (string $name): array {
            $permission = Permission::updateOrCreate(['name' => $name], ['label' => str_replace('.', ' ', ucfirst($name))]);

            return [$name => $permission];
        });

        $roles = [
            'SUPER_ADMIN' => self::PERMISSIONS,
            'ADMIN' => array_values(array_diff(self::PERMISSIONS, ['emergency_stop.manage'])),
            'TRADER' => [
                'dashboard.view', 'strategies.view', 'strategies.create', 'strategies.update',
                'risk_profiles.view', 'risk_engine.view', 'risk_engine.evaluate', 'risk_engine.lock',
                'broker_accounts.view', 'settings.view',
                'preferences.view', 'preferences.update', 'notifications.view',
                'notifications.update', 'simulation_orders.create',
                'trading.read', 'signals.view', 'simulation_lifecycle.create',
                'simulation_lifecycle.evaluate', 'simulation_lifecycle.execute',
                'simulation_orders.cancel', 'simulation_positions.manage',
                'mt5.read', 'mt5.sync', 'mt5.reconcile',
                'market.configure',
                'execution.view', 'execution.confirm', 'execution.execute', 'execution.recover', 'execution.reconcile',
                'trade_management.view', 'trade_management.manage', 'trade_management.recover',
                'analytics.view', 'analytics.manage', 'analytics.export',
                'backtest.view', 'backtest.run', 'backtest.export',
            ],
            'ANALYST' => [
                'dashboard.view', 'strategies.view', 'risk_profiles.view', 'risk_engine.view',
                'broker_accounts.view', 'settings.view', 'preferences.view',
                'preferences.update', 'notifications.view', 'notifications.update',
                'trading.read', 'signals.view', 'mt5.read', 'execution.view', 'trade_management.view',
                'analytics.view', 'analytics.export', 'backtest.view', 'backtest.export',
            ],
            'VIEWER' => [
                'dashboard.view', 'strategies.view', 'settings.view',
                'preferences.view', 'notifications.view',
                'trading.read', 'mt5.read', 'risk_engine.view', 'execution.view', 'trade_management.view',
                'analytics.view', 'backtest.view',
            ],
        ];

        foreach ($roles as $name => $grants) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => ucwords(strtolower(str_replace('_', ' ', $name)))]);
            $role->permissions()->sync(collect($grants)->map(fn (string $permission) => $permissions[$permission]->id));
        }
    }
}
