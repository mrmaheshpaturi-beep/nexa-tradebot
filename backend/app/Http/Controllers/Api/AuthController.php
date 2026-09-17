<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $candidate = User::where('email', $credentials['email'])->first();
            $this->audit->record('auth.login', $candidate, request: $request, description: 'Authentication attempt failed.', result: 'FAILED');

            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if ($request->user()->status !== UserStatus::Active) {
            $this->audit->record('auth.login', $request->user(), request: $request, description: 'Inactive account login blocked.', result: 'BLOCKED');
            Auth::logout();

            return response()->json(['message' => 'Account is not active.'], 403);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $request->user(), request: $request, description: 'User authenticated.');

        return response()->json(['data' => $request->user()->load('roles.permissions', 'preference')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->load('roles.permissions', 'preference')]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.logout', $request->user(), request: $request, description: 'User session ended.');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->json(['message' => 'Logged out.']);
    }
}
