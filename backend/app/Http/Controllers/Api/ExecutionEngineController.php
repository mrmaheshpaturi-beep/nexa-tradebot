<?php

namespace App\Http\Controllers\Api;

use App\Enums\TradingEnvironment;
use App\Execution\DemoAccountVerifier;
use App\Execution\ExecutionConfirmationService;
use App\Execution\ExecutionEngineService;
use App\Http\Controllers\Controller;
use App\Models\BrokerAccount;
use App\Models\ExecutionCommand;
use App\Models\ExecutionConfirmation;
use App\Models\ExecutionEvent;
use App\Models\ExecutionResult;
use App\Models\ServiceHeartbeat;
use App\Models\TradeIntent;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionEngineController extends Controller
{
    public function __construct(
        private readonly ExecutionEngineService $engine,
        private readonly ExecutionConfirmationService $confirmations,
        private readonly DemoAccountVerifier $verifier,
        private readonly SettingsService $settings,
    ) {}

    public function health(): JsonResponse
    {
        $hb = ServiceHeartbeat::query()->where('service', 'EXECUTION_ENGINE')->latest('observed_at')->first();
        $demoEnabled = $this->settings->value('allow_demo_execution') === true;

        return response()->json(['data' => [
            'phase' => 10,
            'status' => 'READY',
            'engine' => 'ExecutionEngine/v1',
            'simulation' => 'SimulationExecutionAdapter',
            'demo_execution' => $demoEnabled ? 'ENABLED_MANUAL_CONFIRM' : 'DISABLED_AS_CONFIGURED',
            'live_execution' => 'HARD_FAIL',
            'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
            'order_send' => 'AUTHORIZED_DEMO_PATH_ONLY',
            'order_send_location' => 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
            'two_step_confirmation' => true,
            'heartbeat' => $hb,
            'apis' => [
                'confirm_step1' => '/api/v1/execution/confirmations',
                'confirm_step2' => '/api/v1/execution/confirmations/{id}/step2',
                'submit' => '/api/v1/execution/demo/submit',
                'recover' => '/api/v1/execution/commands/{id}/recover',
                'reconcile' => '/api/v1/execution/reconcile',
            ],
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json(['data' => [
            'phase' => 10,
            'disclaimer' => 'DEMO EXECUTION ONLY — not live funds. Auto Demo is OFF.',
            'allow_demo_execution' => $this->settings->value('allow_demo_execution') === true,
            'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
            'allow_live_execution' => false,
            'commands' => ExecutionCommand::query()->where('user_id', $userId)->where('environment', 'DEMO')->latest('id')->limit(20)->get(),
            'confirmations' => ExecutionConfirmation::query()->where('user_id', $userId)->latest('id')->limit(20)->get(),
            'results' => ExecutionResult::query()->where('user_id', $userId)->latest('id')->limit(20)->get(),
            'events' => ExecutionEvent::query()->where('user_id', $userId)->latest('id')->limit(30)->get(),
            'unknown_count' => ExecutionCommand::query()
                ->where('user_id', $userId)
                ->where('environment', 'DEMO')
                ->where('submission_state', 'UNKNOWN')
                ->count(),
            'execution' => [
                'order_send' => true,
                'authorized_path_only' => true,
                'live' => false,
            ],
        ]]);
    }

    public function verifyAccount(string $brokerAccount, Request $request): JsonResponse
    {
        $account = BrokerAccount::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($brokerAccount): void {
                $query->where('public_id', $brokerAccount)->orWhere('id', $brokerAccount);
            })
            ->firstOrFail();
        $verified = $this->verifier->verify($account, true);

        return response()->json(['data' => [
            'account' => $account->fresh(),
            'verification' => $verified,
            'live_hard_fail' => true,
        ]]);
    }

    public function startConfirmation(TradeIntent $tradeIntent, Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        $result = $this->confirmations->startStep1($tradeIntent, $data['idempotency_key'], $request);

        return response()->json(['data' => [
            'confirmation' => $result['confirmation'],
            'challenge_token' => $result['challenge_token'],
            'replayed' => $result['replayed'],
            'disclaimer' => 'DEMO — complete step 2 before submit. Auto Demo is OFF.',
        ]], $result['replayed'] ? 200 : 201);
    }

    public function completeConfirmation(ExecutionConfirmation $confirmation, Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'min:16'],
        ]);
        $result = $this->confirmations->completeStep2($confirmation, $data['challenge_token'], $request);

        return response()->json(['data' => [
            'confirmation' => $result['confirmation'],
            'confirm_token' => $result['confirm_token'],
            'disclaimer' => 'DEMO — use confirm_token for the sole submit call.',
        ]]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trade_intent_public_id' => ['required', 'string'],
            'confirmation_public_id' => ['required', 'string'],
            'confirm_token' => ['required', 'string', 'min:16'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        $intent = TradeIntent::query()
            ->where('public_id', $data['trade_intent_public_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $confirmation = ExecutionConfirmation::query()
            ->where('public_id', $data['confirmation_public_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->engine->submitDemo(
            $intent,
            $confirmation,
            $data['confirm_token'],
            $data['idempotency_key'],
            $request,
        );

        return response()->json(['data' => [
            'command' => $result['command'],
            'result' => $result['result'],
            'replayed' => $result['replayed'],
            'environment' => TradingEnvironment::Demo->value,
            'auto_demo' => false,
        ]], $result['replayed'] ? 200 : 201);
    }

    public function showCommand(ExecutionCommand $executionCommand, Request $request): JsonResponse
    {
        abort_unless($executionCommand->user_id === $request->user()->id, 404);

        return response()->json(['data' => $executionCommand->load(['order.position', 'executionResult'])]);
    }

    public function recover(ExecutionCommand $executionCommand, Request $request): JsonResponse
    {
        $command = $this->engine->recoverUnknown($executionCommand, $request);

        return response()->json(['data' => [
            'command' => $command,
            'blind_retry' => false,
            'disclaimer' => 'Recovery never re-invokes order_send.',
        ]]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'broker_account_id' => ['nullable', 'integer'],
        ]);
        $run = $this->engine->reconcile($request, $data['broker_account_id'] ?? null);

        return response()->json(['data' => $run], 201);
    }

    public function metrics(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json(['data' => [
            'demo_commands' => ExecutionCommand::query()->where('user_id', $userId)->where('environment', 'DEMO')->count(),
            'unknown' => ExecutionCommand::query()->where('user_id', $userId)->where('submission_state', 'UNKNOWN')->count(),
            'results' => ExecutionResult::query()->where('user_id', $userId)->count(),
            'events' => ExecutionEvent::query()->where('user_id', $userId)->count(),
            'order_send_locations' => 1,
            'live_execution' => false,
            'auto_demo' => false,
        ]]);
    }
}
