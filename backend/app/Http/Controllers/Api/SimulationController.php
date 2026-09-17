<?php

namespace App\Http\Controllers\Api;

use App\Contracts\SimulationRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SimulationController extends Controller
{
    public function __construct(private readonly SimulationRepository $simulation) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->simulation->systemStatus()]);
    }

    public function order(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', Rule::in(['XAUUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'NAS100', 'BTCUSD'])],
            'direction' => ['required', Rule::in(['BUY', 'SELL'])],
            'volume' => ['required', 'numeric', 'min:0.01', 'max:5'],
        ]);

        return response()->json(['data' => $this->simulation->createOrder([
            'symbol' => $validated['symbol'],
            'direction' => $validated['direction'],
            'volume' => (float) $validated['volume'],
        ])], 201);
    }
}
