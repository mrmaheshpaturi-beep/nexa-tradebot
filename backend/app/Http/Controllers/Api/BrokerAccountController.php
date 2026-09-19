<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerAccountRequest;
use App\Models\BrokerAccount;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $account = DB::transaction(function () use ($request): BrokerAccount {
            $account = $request->user()->brokerAccounts()->create([
                ...$request->validated(),
                'environment' => in_array($request->input('environment'), ['SIMULATION', 'DEMO'], true)
                    ? $request->input('environment')
                    : 'SIMULATION',
                'status' => 'DISCONNECTED',
                'broker_login' => $request->input('broker_login'),
                'broker_server' => $request->input('broker_server'),
                'created_by' => $request->user()->id,
            ]);
            $env = $account->environment instanceof \App\Enums\TradingEnvironment
                ? $account->environment->value
                : (string) $account->environment;
            if ($env === 'LIVE') {
                abort(422, 'LIVE accounts cannot be created.');
            }
            $this->audit->record('broker_account.created', $account, [], $account->toArray(), $request);

            return $account;
        });

        return response()->json(['data' => $account], 201);
    }

    public function update(BrokerAccountRequest $request, BrokerAccount $brokerAccount): JsonResponse
    {
        abort_unless($brokerAccount->user_id === $request->user()->id, 404);
        $this->assertOwnedRiskProfile($request);
        DB::transaction(function () use ($request, $brokerAccount): void {
            $before = $brokerAccount->toArray();
            $brokerAccount->update([
                ...$request->validated(),
                'environment' => 'SIMULATION',
            ]);
            $this->audit->record('broker_account.updated', $brokerAccount, $before, $brokerAccount->toArray(), $request);
        });

        return response()->json(['data' => $brokerAccount]);
    }

    private function assertOwnedRiskProfile(Request $request): void
    {
        if ($request->filled('risk_profile_id')) {
            abort_unless($request->user()->riskProfiles()->whereKey($request->integer('risk_profile_id'))->exists(), 422);
        }
    }
}
