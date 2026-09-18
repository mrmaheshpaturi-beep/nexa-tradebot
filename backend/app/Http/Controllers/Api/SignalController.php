<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignalIntentRequest;
use App\Models\Signal;
use App\Services\SignalEngineService;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    public function __construct(
        private readonly TradeLifecycleService $lifecycle,
        private readonly SignalEngineService $signals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Signal::query()
            ->where('user_id', $request->user()->id)
            ->with(['strategy', 'instrument'])
            ->latest('generated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('symbol')) {
            $query->where('symbol', strtoupper((string) $request->string('symbol')));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }

        return response()->json(['data' => $query->paginate()]);
    }

    public function show(Request $request, Signal $signal): JsonResponse
    {
        abort_unless($signal->user_id === $request->user()->id, 404);

        return response()->json(['data' => [
            ...$signal->load(['strategy', 'instrument', 'intent'])->toArray(),
            'explainability' => [
                'score' => $signal->score,
                'confluence_score' => $signal->confluence_score,
                'score_breakdown' => $signal->score_breakdown,
                'confluence' => $signal->confluence,
                'explanation' => $signal->explanation,
                'disclaimer' => 'Score is a transparent confluence measure (0–100), not a win probability or guarantee.',
            ],
            'execution' => [
                'order_send' => false,
                'auto_simulation' => (bool) $signal->auto_simulation,
                'broker_auto_trading' => false,
            ],
        ]]);
    }

    public function createIntent(SignalIntentRequest $request, Signal $signal): JsonResponse
    {
        abort_unless($signal->user_id === $request->user()->id, 404);
        abort_unless($signal->environment->value === 'SIMULATION', 422, 'Only SIMULATION signals can create intents.');

        $result = $this->lifecycle->createIntentFromSignal($signal, $request->validated(), $request);

        return response()->json(['data' => [
            ...$result['intent']->load(['riskDecision', 'executionCommand'])->toArray(),
            'idempotent_replay' => $result['replayed'],
            'note' => 'Intent created for SIMULATION only. No broker order_send.',
        ]], $result['replayed'] ? 200 : 201);
    }

    public function invalidate(Request $request, Signal $signal): JsonResponse
    {
        abort_unless($signal->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->signals->invalidate($signal, $validated['reason'])]);
    }

    public function expireDue(Request $request): JsonResponse
    {
        $count = $this->signals->expireDue();

        return response()->json(['data' => ['expired' => $count]]);
    }
}
