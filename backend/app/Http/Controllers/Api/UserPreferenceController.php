<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPreferenceRequest;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->preference()->firstOrCreate()]);
    }

    public function update(UserPreferenceRequest $request): JsonResponse
    {
        $preference = $request->user()->preference()->firstOrCreate();
        $before = $preference->toArray();
        $preference->update($request->validated());
        $this->audit->record('preference.updated', $preference, $before, $preference->toArray(), $request);

        return response()->json(['data' => $preference]);
    }
}
