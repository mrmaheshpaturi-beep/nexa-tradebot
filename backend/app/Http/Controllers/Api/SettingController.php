<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingRequest;
use App\Models\ApplicationSetting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => ApplicationSetting::orderBy('key')->get()]);
    }

    public function update(SettingRequest $request, string $key): JsonResponse
    {
        if ($key === 'emergency_stop') {
            throw ValidationException::withMessages([
                'key' => 'Use the permission-protected emergency stop endpoint.',
            ]);
        }

        return response()->json(['data' => $this->settings->put(
            $key,
            $request->validated('value'),
            $request->boolean('is_public'),
            $request,
        )]);
    }

    public function emergencyStop(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(['data' => $this->settings->put(
            'emergency_stop',
            $validated['enabled'],
            true,
            $request,
        )]);
    }
}
