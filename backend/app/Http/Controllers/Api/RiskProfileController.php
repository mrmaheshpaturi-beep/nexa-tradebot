<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RiskProfileRequest;
use App\Models\RiskProfile;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskProfileController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->riskProfiles()->paginate()]);
    }

    public function store(RiskProfileRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request): RiskProfile {
            if ($request->boolean('is_default')) {
                $request->user()->riskProfiles()->update(['is_default' => false]);
            }
            $profile = $request->user()->riskProfiles()->create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $this->audit->record('risk_profile.created', $profile, [], $profile->toArray(), $request);

            return $profile;
        });

        return response()->json(['data' => $profile], 201);
    }

    public function update(RiskProfileRequest $request, RiskProfile $riskProfile): JsonResponse
    {
        abort_unless($riskProfile->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($request, $riskProfile): void {
            if ($request->boolean('is_default')) {
                $request->user()->riskProfiles()->where('id', '!=', $riskProfile->id)->update(['is_default' => false]);
            }
            $before = $riskProfile->toArray();
            $riskProfile->update([...$request->validated(), 'updated_by' => $request->user()->id]);
            $this->audit->record('risk_profile.updated', $riskProfile, $before, $riskProfile->toArray(), $request);
        });

        return response()->json(['data' => $riskProfile]);
    }
}
