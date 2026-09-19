<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertSeverity;
use App\Enums\ValidationSessionMode;
use App\Http\Controllers\Controller;
use App\Models\OpsIncident;
use App\Observability\ObservabilityService;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ObservabilityController extends Controller
{
    public function __construct(private readonly ObservabilityService $obs) {}

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->obs->healthPayload()]);
    }

    public function liveness(): JsonResponse
    {
        return response()->json(['data' => [
            'status' => 'ALIVE',
            'phase' => ObservabilitySafety::PHASE,
        ]]);
    }

    public function readiness(): JsonResponse
    {
        $env = $this->obs->env()->validateCurrent();

        return response()->json(['data' => [
            'status' => $env['ok'] ? 'READY' : 'NOT_READY',
            'env' => $env,
            'phase' => ObservabilitySafety::PHASE,
        ]], $env['ok'] ? 200 : 503);
    }

    public function tradingReadiness(): JsonResponse
    {
        return response()->json(['data' => $this->obs->tradingReadiness()]);
    }

    public function operations(): JsonResponse
    {
        return response()->json(['data' => $this->obs->operationsDashboard()]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $label = $request->query('evidence_label');
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'value' => ['required', 'numeric'],
                'category' => ['required', 'string'],
                'evidence_label' => ['required', 'string'],
                'labels' => ['nullable', 'array'],
                'unit' => ['nullable', 'string'],
                'sample_count' => ['nullable', 'integer', 'min:1'],
            ]);
            try {
                $row = $this->obs->metrics()->record(
                    $data['name'],
                    (float) $data['value'],
                    $data['category'],
                    $data['evidence_label'],
                    $data['labels'] ?? [],
                    $data['unit'] ?? null,
                    (int) ($data['sample_count'] ?? 1),
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['data' => $row], 201);
        }

        return response()->json(['data' => $this->obs->metrics()->summarize($label)]);
    }

    public function healthHistory(): JsonResponse
    {
        return response()->json(['data' => $this->obs->health()->history()]);
    }

    public function watchdog(): JsonResponse
    {
        return response()->json(['data' => $this->obs->watchdog()->inspect()]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'providers' => $this->obs->alerts()->providers(),
            'items' => $this->obs->alerts()->center($request->query('status')),
        ]]);
    }

    public function raiseAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string'],
            'title' => ['required', 'string'],
            'severity' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
        ]);
        $alert = $this->obs->alerts()->raise(
            $data['category'],
            $data['title'],
            $data['severity'] ?? AlertSeverity::Warning->value,
            $data['body'] ?? null,
            $data['payload'] ?? [],
            $request->user()?->id,
        );

        return response()->json(['data' => $alert], 201);
    }

    public function ackAlert(Request $request, string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->obs->alerts()->acknowledge($publicId, $request->user())]);
    }

    public function resolveAlert(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->obs->alerts()->resolve($publicId)]);
    }

    public function validationLab(): JsonResponse
    {
        return response()->json(['data' => $this->obs->validation()->labSummary()]);
    }

    public function startValidation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string'],
            'config' => ['nullable', 'array'],
        ]);
        $session = $this->obs->validation()->start(
            $request->user(),
            ValidationSessionMode::from(strtoupper($data['mode'])),
            $data['config'] ?? [],
        );

        return response()->json(['data' => $session], 201);
    }

    public function observeValidation(Request $request, string $publicId): JsonResponse
    {
        $session = \App\Models\ValidationSession::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'stage' => ['required', 'string'],
            'outcome' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
            'research_only' => ['nullable', 'boolean'],
            'symbol' => ['nullable', 'string'],
            'strategy_key' => ['nullable', 'string'],
        ]);
        $obs = $this->obs->validation()->observe(
            $session,
            $data['stage'],
            $data['outcome'],
            $data['payload'] ?? [],
            (bool) ($data['research_only'] ?? false),
            $data['symbol'] ?? null,
            $data['strategy_key'] ?? null,
        );

        return response()->json(['data' => ['observation' => $obs, 'session' => $session->fresh()]], 201);
    }

    public function dataQuality(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'source' => ['required', 'string'],
                'score' => ['required', 'numeric'],
                'symbol' => ['nullable', 'string'],
                'issues' => ['nullable', 'array'],
                'clock_drift_ms' => ['nullable', 'numeric'],
            ]);
            $row = $this->obs->dataQuality()->evaluate(
                $data['source'],
                (float) $data['score'],
                $data['symbol'] ?? null,
                $data['issues'] ?? [],
                isset($data['clock_drift_ms']) ? (float) $data['clock_drift_ms'] : null,
            );

            return response()->json(['data' => $row], 201);
        }

        return response()->json(['data' => [
            'latest' => $this->obs->dataQuality()->latest(),
            'blocks_new_trades' => $this->obs->dataQuality()->currentlyBlocksNewTrades(),
        ]]);
    }

    public function drift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strategy_key' => ['required', 'string'],
            'evidence_label' => ['required', 'string'],
            'metrics' => ['required', 'array'],
            'safety_issue' => ['nullable', 'boolean'],
        ]);
        $row = $this->obs->drift()->check(
            $data['strategy_key'],
            $data['evidence_label'],
            $data['metrics'],
            (bool) ($data['safety_issue'] ?? false),
        );

        return response()->json(['data' => $row], 201);
    }

    public function backup(): JsonResponse
    {
        return response()->json(['data' => $this->obs->backups()->run()], 201);
    }

    public function disasterRecovery(): JsonResponse
    {
        return response()->json(['data' => $this->obs->backups()->disasterRecoveryChecklist()]);
    }

    public function circuits(): JsonResponse
    {
        return response()->json(['data' => $this->obs->circuits()->status()]);
    }

    public function circuitEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'event' => ['required', 'in:success,failure'],
        ]);
        $row = $data['event'] === 'success'
            ? $this->obs->circuits()->recordSuccess($data['name'])
            : $this->obs->circuits()->recordFailure($data['name']);

        return response()->json(['data' => $row]);
    }

    public function resources(): JsonResponse
    {
        return response()->json(['data' => $this->obs->resources()->snapshot()]);
    }

    public function scorecard(): JsonResponse
    {
        return response()->json(['data' => $this->obs->readinessScorecard()]);
    }

    public function envCheck(): JsonResponse
    {
        return response()->json(['data' => $this->obs->env()->validateCurrent()]);
    }

    public function incidents(): JsonResponse
    {
        $items = OpsIncident::query()->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $items]);
    }

    public function openIncident(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'severity' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
        ]);
        $incident = OpsIncident::query()->create([
            'public_id' => (string) Str::uuid(),
            'title' => $data['title'],
            'severity' => strtoupper($data['severity'] ?? 'WARNING'),
            'status' => 'OPEN',
            'summary' => $data['summary'] ?? null,
            'timeline' => [['at' => now()->toIso8601String(), 'event' => 'OPENED']],
            'opened_at' => now(),
        ]);

        return response()->json(['data' => $incident], 201);
    }

    public function comparisons(): JsonResponse
    {
        return response()->json(['data' => [
            'phase' => ObservabilitySafety::PHASE,
            'note' => 'Evidence labels compared separately — never mixed',
            'labels' => ObservabilitySafety::EVIDENCE_LABELS,
            'metrics' => $this->obs->metrics()->summarize(),
        ]]);
    }

    public function riskOps(): JsonResponse
    {
        return response()->json(['data' => [
            'phase' => ObservabilitySafety::PHASE,
            'emergency_stop' => $this->obs->health()->evaluate()->checks['emergency_stop'] ?? null,
            'new_entries_blocked' => $this->obs->health()->blocksNewEntries(),
            'data_quality_blocks' => $this->obs->dataQuality()->currentlyBlocksNewTrades(),
        ]]);
    }

    public function executionQuality(): JsonResponse
    {
        return response()->json(['data' => $this->obs->metrics()->summarize('DEMO')]);
    }

    public function reconciliationOps(): JsonResponse
    {
        return response()->json(['data' => [
            'unknown_execution_policy' => ObservabilitySafety::UNKNOWN_EXECUTION_POLICY,
            'decision_unknown' => $this->obs->resources()->executionRetryDecision('UNKNOWN'),
        ]]);
    }

    public function queueOps(): JsonResponse
    {
        return response()->json(['data' => $this->obs->resources()->snapshot()['queue']]);
    }

    public function serverOps(): JsonResponse
    {
        return response()->json(['data' => $this->obs->resources()->snapshot()]);
    }

    public function failureScenarios(): JsonResponse
    {
        return response()->json(['data' => [
            'scenarios' => $this->obs->failures()->scenarios(),
            'soak' => $this->obs->soak()->plan(60, 'CI_SHORT'),
            'manual_soak' => $this->obs->soak()->plan(86400, 'MANUAL_SOAK_DAYS'),
        ]]);
    }

    public function refuseLiveProduction(): JsonResponse
    {
        return response()->json([
            'message' => 'LIVE_PRODUCTION environment does not exist in Phase 15.',
            'data' => [
                'live_production' => false,
                'live_auto_exists' => false,
                'allowed' => ObservabilitySafety::ALLOWED_ENVIRONMENTS,
            ],
        ], 403);
    }
}
