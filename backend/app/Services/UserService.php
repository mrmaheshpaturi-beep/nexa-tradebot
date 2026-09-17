<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(private readonly AuditService $audit) {}

    public function save(array $data, Request $request, ?User $user = null): User
    {
        return DB::transaction(function () use ($data, $request, $user): User {
            $role = Role::where('name', $data['role'])->firstOrFail();
            $before = $user?->only(['name', 'email', 'status']) ?? [];
            $beforeRoles = $user?->roles()->pluck('name')->all() ?? [];
            if ($user) {
                $before['roles'] = $beforeRoles;
            }
            $attributes = Arr::only($data, ['name', 'email', 'status', 'password']);
            if (empty($attributes['password'])) {
                unset($attributes['password']);
            }
            $user ??= new User;
            $user->fill($attributes)->save();
            $user->roles()->sync([$role->id]);
            $user->preference()->firstOrCreate();
            $after = [...$user->only(['name', 'email', 'status']), 'roles' => [$role->name]];
            $this->audit->record($before ? 'user.updated' : 'user.created', $user, $before, $after, $request);
            if ($beforeRoles && $beforeRoles !== [$role->name]) {
                $this->audit->record(
                    'user.role_updated',
                    $user,
                    ['roles' => $beforeRoles],
                    ['roles' => [$role->name]],
                    $request,
                    "User role changed to {$role->name}.",
                );
            }

            return $user->load('roles', 'preference');
        });
    }

    public function setStatus(User $user, UserStatus $status, Request $request): User
    {
        return DB::transaction(function () use ($user, $status, $request): User {
            $before = $user->status->value;
            $user->update(['status' => $status]);
            $this->audit->record(
                'user.status_updated',
                $user,
                ['status' => $before],
                ['status' => $status->value],
                $request,
                "User status changed to {$status->value}.",
            );

            return $user->fresh()->load('roles');
        });
    }
}
