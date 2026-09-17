<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerAccountRequest;
use App\Models\BrokerAccount;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrokerAccountController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->brokerAccounts()->with('riskProfile')->paginate()]);
    }

    public function store(BrokerAccountRequest $request): JsonResponse
    {
        $this->assertOwnedRiskProfile($request);
        $account = $request->user()->brokerAccounts()->create([
            ...$request->validated(),
            'environment' => 'SIMULATION',
            'status' => 'DISCONNECTED',
            'created_by' => $request->user()->id,
        ]);
        $this->audit->record('broker_account.created', $account, [], $account->toArray(), $request);

        return response()->json(['data' => $account], 201);
    }

    public function update(BrokerAccountRequest $request, BrokerAccount $brokerAccount): JsonResponse
    {
        abort_unless($brokerAccount->user_id === $request->user()->id, 404);
        $this->assertOwnedRiskProfile($request);
        $before = $brokerAccount->toArray();
        $brokerAccount->update([
            ...$request->validated(),
            'environment' => 'SIMULATION',
        ]);
        $this->audit->record('broker_account.updated', $brokerAccount, $before, $brokerAccount->toArray(), $request);

        return response()->json(['data' => $brokerAccount]);
    }

    private function assertOwnedRiskProfile(Request $request): void
    {
        if ($request->filled('risk_profile_id')) {
            abort_unless($request->user()->riskProfiles()->whereKey($request->integer('risk_profile_id'))->exists(), 422);
        }
    }
}
