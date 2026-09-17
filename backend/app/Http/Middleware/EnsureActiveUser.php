<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->user()?->status !== UserStatus::Active) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return new JsonResponse(['message' => 'Account is not active.'], 403);
        }

        return $next($request);
    }
}
