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
        'broker_accounts.view', 'broker_accounts.create', 'broker_accounts.update',
        'settings.view', 'settings.update', 'emergency_stop.manage',
        'preferences.view', 'preferences.update',
        'notifications.view', 'notifications.update',
        'audit_logs.view', 'simulation_orders.create',
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
                'risk_profiles.view', 'broker_accounts.view', 'settings.view',
                'preferences.view', 'preferences.update', 'notifications.view',
                'notifications.update', 'simulation_orders.create',
            ],
            'ANALYST' => [
                'dashboard.view', 'strategies.view', 'risk_profiles.view',
                'broker_accounts.view', 'settings.view', 'preferences.view',
                'preferences.update', 'notifications.view', 'notifications.update',
            ],
            'VIEWER' => [
                'dashboard.view', 'strategies.view', 'settings.view',
                'preferences.view', 'notifications.view',
            ],
        ];

        foreach ($roles as $name => $grants) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => ucwords(strtolower(str_replace('_', ' ', $name)))]);
            $role->permissions()->sync(collect($grants)->map(fn (string $permission) => $permissions[$permission]->id));
        }
    }
}
