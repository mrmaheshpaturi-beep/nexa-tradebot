<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdempotentActionRequest;
use App\Http\Requests\TradeIntentRequest;
use App\Models\TradeIntent;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeIntentController extends Controller
{
    public function __construct(private readonly TradeLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => TradeIntent::query()
            ->where('user_id', $request->user()->id)
            ->with(['instrument', 'brokerAccount', 'riskDecision', 'executionCommand.order'])
            ->latest()
            ->paginate()]);
    }

    public function store(TradeIntentRequest $request): JsonResponse
    {
        $result = $this->lifecycle->createIntent($request->validated(), $request);

        return response()->json(['data' => [
            ...$result['intent']->load(['instrument', 'brokerAccount'])->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, TradeIntent $tradeIntent): JsonResponse
    {
        abort_unless($tradeIntent->user_id === $request->user()->id, 404);

        return response()->json(['data' => $tradeIntent->load([
            'instrument', 'brokerAccount', 'strategy', 'signal', 'riskDecision',
            'executionCommand.order.deals', 'executionCommand.order.position.events',
        ])]);
    }

    public function evaluate(Request $request, TradeIntent $tradeIntent): JsonResponse
    {
        return response()->json(['data' => $this->lifecycle->evaluate($tradeIntent, $request)]);
    }

    public function execute(IdempotentActionRequest $request, TradeIntent $tradeIntent): JsonResponse
    {
        $result = $this->lifecycle->execute($tradeIntent, $request->string('idempotency_key')->toString(), $request);

        return response()->json(['data' => [
            ...$result['command']->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }
}
