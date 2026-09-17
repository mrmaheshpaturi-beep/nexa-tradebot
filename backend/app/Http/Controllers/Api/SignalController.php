<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignalIntentRequest;
use App\Models\Signal;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    public function __construct(private readonly TradeLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Signal::query()
            ->where('user_id', $request->user()->id)
            ->with(['strategy', 'instrument'])
            ->latest('generated_at')
            ->paginate()]);
    }

    public function show(Request $request, Signal $signal): JsonResponse
    {
        abort_unless($signal->user_id === $request->user()->id, 404);

        return response()->json(['data' => $signal->load(['strategy', 'instrument', 'intent'])]);
    }

    public function createIntent(SignalIntentRequest $request, Signal $signal): JsonResponse
    {
        $result = $this->lifecycle->createIntentFromSignal($signal, $request->validated(), $request);

        return response()->json(['data' => [
            ...$result['intent']->load(['riskDecision', 'executionCommand'])->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }
}
