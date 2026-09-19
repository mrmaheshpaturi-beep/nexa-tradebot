<?php

namespace App\Http\Controllers\Api;

use App\Automation\AutomatedTradingOrchestrator;
use App\Automation\AutomationProfileService;
use App\Automation\Support\AutomationSafety;
use App\Models\AutomationEvent;
use App\Models\AutomationNotification;
use App\Models\AutomationProfile;
use App\Models\AutomationSession;
use App\Models\AutomationWorkflow;
use App\Models\ServiceHeartbeat;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomatedTradingOrchestrator $orchestrator,
        private readonly AutomationProfileService $profiles,
        private readonly SettingsService $settings,
    ) {}

    public function health(): JsonResponse
    {
        $hb = ServiceHeartbeat::query()
            ->where('service', 'AUTOMATED_TRADING_ORCHESTRATOR')
            ->latest('observed_at')
            ->first();

        return response()->json(['data' => [
            'phase' => 14,
            'engine_version' => AutomationSafety::ENGINE_VERSION,
            'status' => $hb ? 'READY' : 'IDLE',
            'default_state' => 'OFF',
            'auto_start_on_boot' => false,
            'modes_allowed' => AutomationSafety::ALLOWED_MODES,
            'live_auto_exists' => false,
            'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
            'allow_demo_execution' => $this->settings->value('allow_demo_execution') === true,
            'allow_live_execution' => false,
            'order_send_phase14' => AutomationSafety::ORDER_SEND_CALL_SITES_IN_PHASE_14,
            'order_send_location' => AutomationSafety::PHASE_10_ORDER_SEND,
            'ui_label' => AutomationSafety::UI_LABEL_DEMO,
            'last_heartbeat_at' => $hb?->observed_at,
        ]]);
    }

    public function controlCenter(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->controlCenter($request->user())]);
    }

    public function preflight(Request $request): JsonResponse
    {
        $mode = strtoupper((string) $request->input('mode', 'DRY_RUN'));
        AutomationSafety::assertModeAllowed($mode);
        $account = null;
        if ($request->filled('broker_account_public_id')) {
            $account = $request->user()->brokerAccounts()
                ->where('public_id', $request->string('broker_account_public_id')->toString())
                ->first();
        }
        $gate = app(\App\Automation\AutomationStartupGate::class);
        $result = $gate->preflight(
            $request->user(),
            \App\Enums\AutomationMode::from($mode),
            $account,
        );

        return response()->json(['data' => $result]);
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationProfile::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate()]);
    }

    public function createProfile(Request $request): JsonResponse
    {
        $profile = $this->profiles->create($request->user(), $request->all());

        return response()->json(['data' => $profile], 201);
    }

    public function validateProfile(Request $request, AutomationProfile $profile): JsonResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->profiles->validate($profile)]);
    }

    public function activateProfile(Request $request, AutomationProfile $profile): JsonResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->profiles->activate($profile)]);
    }

    public function reviseProfile(Request $request, AutomationProfile $profile): JsonResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->profiles->revise($profile, $request->all())]);
    }

    public function startStep1(Request $request): JsonResponse
    {
        $result = $this->orchestrator->startStep1($request->user(), $request->all(), $request);

        return response()->json(['data' => $result], 201);
    }

    public function startStep2(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->startStep2($request->user(), $session, $request->all(), $request)]);
    }

    public function pause(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->pause(
            $request->user(),
            $session,
            (string) $request->input('reason', 'OPERATOR_PAUSE'),
        )]);
    }

    public function resume(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->resume($request->user(), $session, $request)]);
    }

    public function stop(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->stop($request->user(), $session, $request)]);
    }

    public function killSwitch(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->killSwitch($request->user(), $session, $request)]);
    }

    public function recover(Request $request, AutomationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->recoverAfterRestart($request->user(), $session)]);
    }

    public function tick(Request $request, AutomationSession $session): JsonResponse
    {
        $candidate = $request->input('candidate', []);
        if (! is_array($candidate)) {
            $candidate = [];
        }

        return response()->json(['data' => $this->orchestrator->tick($request->user(), $session, $candidate)]);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationSession::query()
            ->where('user_id', $request->user()->id)
            ->with(['profile', 'brokerAccount'])
            ->latest('id')
            ->paginate()]);
    }

    public function showSession(Request $request, AutomationSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        return response()->json(['data' => $session->load(['profile', 'brokerAccount'])]);
    }

    public function workflows(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationWorkflow::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate()]);
    }

    public function showWorkflow(Request $request, AutomationWorkflow $workflow): JsonResponse
    {
        abort_unless($workflow->user_id === $request->user()->id, 404);

        return response()->json(['data' => $workflow]);
    }

    public function events(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationEvent::query()
            ->where('user_id', $request->user()->id)
            ->latest('occurred_at')
            ->paginate()]);
    }

    public function rejections(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationWorkflow::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('state', ['REJECTED', 'RISK_REJECTED', 'EXECUTION_REJECTED', 'EXPIRED'])
            ->latest('id')
            ->paginate()]);
    }

    public function executions(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationWorkflow::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('state', ['EXECUTION_SUBMITTED', 'EXECUTION_FILLED', 'EXECUTION_UNKNOWN', 'MANAGEMENT_ACTIVE', 'DRY_RUN_COMPLETE'])
            ->latest('id')
            ->paginate()]);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['data' => AutomationNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate()]);
    }

    public function enableAutoDemoSetting(Request $request): JsonResponse
    {
        $phrase = (string) $request->input('confirmation_phrase', '');
        if ($phrase !== 'ENABLE AUTO DEMO TRADING') {
            return response()->json(['message' => 'Confirmation phrase required.', 'errors' => [
                'confirmation_phrase' => ['Must equal ENABLE AUTO DEMO TRADING'],
            ]], 422);
        }
        // Unlock path: require allow_demo first
        if ($this->settings->value('allow_demo_execution') !== true) {
            return response()->json(['message' => 'allow_demo_execution must be true first.', 'errors' => [
                'allow_demo_execution' => ['Enable DEMO execution before AUTO DEMO.'],
            ]], 422);
        }
        $setting = $this->settings->put('auto_demo_execution', true, true, $request);

        return response()->json(['data' => [
            'key' => 'auto_demo_execution',
            'value' => true,
            'label' => 'AUTO DEMO TRADING enabled (not AUTO LIVE)',
            'setting' => $setting,
        ]]);
    }

    /** Refuse LIVE_AUTO explicitly. */
    public function refuseLiveAuto(): JsonResponse
    {
        return response()->json([
            'message' => 'LIVE_AUTO does not exist and is hard-rejected.',
            'data' => [
                'live_auto_exists' => false,
                'allowed_modes' => AutomationSafety::ALLOWED_MODES,
            ],
        ], 403);
    }
}
