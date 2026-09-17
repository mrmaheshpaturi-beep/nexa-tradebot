<?php

namespace Tests;

use App\Models\Role;
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
}
