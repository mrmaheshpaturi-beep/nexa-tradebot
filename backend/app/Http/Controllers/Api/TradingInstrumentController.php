<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingInstrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradingInstrumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => TradingInstrument::query()
            ->when($request->filled('symbol'), fn ($query) => $query->where('symbol', $request->string('symbol')->upper()))
            ->orderBy('symbol')->paginate()]);
    }

    public function show(TradingInstrument $instrument): JsonResponse
    {
        return response()->json(['data' => $instrument]);
    }
}
