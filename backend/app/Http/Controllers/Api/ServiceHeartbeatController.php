<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceHeartbeatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => ServiceHeartbeat::query()
            ->with('terminal')
            ->when($request->filled('service'), fn ($query) => $query->where('service', $request->string('service')))
            ->latest('observed_at')
            ->paginate()]);
    }
}
