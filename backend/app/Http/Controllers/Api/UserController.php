<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => User::with('roles', 'preference')->paginate()]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->users->save($request->validated(), $request)], 201);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->save($request->validated(), $request, $user)]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->setStatus($user, UserStatus::Active, $request)]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->setStatus($user, UserStatus::Suspended, $request)]);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->setStatus($user, UserStatus::Disabled, $request)]);
    }
}
