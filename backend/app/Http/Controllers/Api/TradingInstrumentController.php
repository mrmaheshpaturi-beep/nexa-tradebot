<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MarketDataProvider;
use App\Http\Controllers\Controller;
use App\Models\TradingInstrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradingInstrumentController extends Controller
{
    public function __construct(private readonly MarketDataProvider $marketData) {}

    public function index(Request $request): JsonResponse
    {
        $instruments = TradingInstrument::query()
            ->when($request->filled('symbol'), fn ($query) => $query->where('symbol', $request->string('symbol')->upper()))
            ->orderBy('symbol')
            ->paginate();
        $instruments->getCollection()->each(fn (TradingInstrument $instrument) => $this->attachMockQuote($instrument));

        return response()->json(['data' => $instruments]);
    }

    public function show(TradingInstrument $instrument): JsonResponse
    {
        $this->attachMockQuote($instrument);

        return response()->json(['data' => $instrument]);
    }

    private function attachMockQuote(TradingInstrument $instrument): void
    {
        $instrument->setAttribute('mock_quote', $this->marketData->getQuote($instrument->symbol));
    }
}
