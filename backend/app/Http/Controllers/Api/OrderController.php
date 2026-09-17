<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdempotentActionRequest;
use App\Models\Order;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly TradeLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['instrument', 'position'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate()]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return response()->json(['data' => $order->load(['instrument', 'deals', 'position.events', 'executionCommand'])]);
    }

    public function cancel(IdempotentActionRequest $request, Order $order): JsonResponse
    {
        $result = $this->lifecycle->cancelOrder($order, $request->string('idempotency_key')->toString(), $request);

        return response()->json(['data' => [
            ...$result['command']->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }
}
