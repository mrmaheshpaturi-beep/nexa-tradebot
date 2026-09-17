<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PositionActionRequest;
use App\Models\ExecutionCommand;
use App\Models\Position;
use App\Services\TradeLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(private readonly TradeLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Position::query()
            ->where('user_id', $request->user()->id)
            ->with(['instrument', 'brokerAccount'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate()]);
    }

    public function show(Request $request, Position $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 404);

        return response()->json(['data' => $position->load([
            'instrument', 'brokerAccount', 'openingOrder', 'deals', 'events.executionCommand',
        ])]);
    }

    public function close(PositionActionRequest $request, Position $position): JsonResponse
    {
        $result = $this->lifecycle->close(
            $position,
            $request->filled('volume') ? $request->float('volume') : null,
            $request->string('idempotency_key')->toString(),
            $request,
        );

        return $this->commandResponse($result);
    }

    public function partialClose(PositionActionRequest $request, Position $position): JsonResponse
    {
        if (! $request->filled('volume')) {
            return response()->json(['message' => 'The volume field is required.', 'errors' => ['volume' => ['The volume field is required.']]], 422);
        }

        $result = $this->lifecycle->close(
            $position,
            $request->float('volume'),
            $request->string('idempotency_key')->toString(),
            $request,
        );

        return $this->commandResponse($result);
    }

    public function modifyProtection(PositionActionRequest $request, Position $position): JsonResponse
    {
        if (! $request->hasAny(['stop_loss', 'take_profit'])) {
            return response()->json(['message' => 'A stop loss or take profit value is required.'], 422);
        }

        return $this->commandResponse($this->lifecycle->modifyProtection($position, $request->validated(), $request));
    }

    public function modifyStopLoss(PositionActionRequest $request, Position $position): JsonResponse
    {
        if (! $request->has('stop_loss')) {
            return response()->json(['message' => 'The stop loss field is required.'], 422);
        }

        return $this->commandResponse($this->lifecycle->modifyStopLoss(
            $position,
            $request->safe()->only(['idempotency_key', 'stop_loss']),
            $request,
        ));
    }

    public function modifyTakeProfit(PositionActionRequest $request, Position $position): JsonResponse
    {
        if (! $request->has('take_profit')) {
            return response()->json(['message' => 'The take profit field is required.'], 422);
        }

        return $this->commandResponse($this->lifecycle->modifyTakeProfit(
            $position,
            $request->safe()->only(['idempotency_key', 'take_profit']),
            $request,
        ));
    }

    /** @param array{command:ExecutionCommand,replayed:bool} $result */
    private function commandResponse(array $result): JsonResponse
    {
        return response()->json(['data' => [
            ...$result['command']->toArray(),
            'idempotent_replay' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }
}
