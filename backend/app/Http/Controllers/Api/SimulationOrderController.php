<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimulationOrderRequest;
use App\Services\SimulationOrderService;
use Illuminate\Http\JsonResponse;

class SimulationOrderController extends Controller
{
    public function __construct(private readonly SimulationOrderService $orders) {}

    public function store(SimulationOrderRequest $request): JsonResponse
    {
        $result = $this->orders->create($request->validated(), $request);

        return response()->json(['data' => [
            ...$result['order']->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }
}
