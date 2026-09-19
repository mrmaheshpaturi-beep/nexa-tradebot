<?php

namespace App\Http\Controllers\Api;

use App\Hardening\ProductionHardeningService;
use App\Hardening\Support\HardeningSafety;
use App\Http\Controllers\Controller;
use App\Models\HardeningDeployVersion;
use App\Models\HardeningWorkerProcess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardeningController extends Controller
{
    public function __construct(private readonly ProductionHardeningService $hardening) {}

    public function matrix(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->matrix()]);
    }

    public function ops(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->ops()->dashboard()]);
    }

    public function tradingReadinessSeparated(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->ops()->tradingReadinessSeparated()]);
    }

    public function environments(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'app_environment' => ['nullable', 'string'],
                'broker_trade_mode' => ['nullable', 'string'],
            ]);

            return response()->json(['data' => $this->hardening->envs()->validate(
                $data['app_environment'] ?? null,
                $data['broker_trade_mode'] ?? null,
            )]);
        }

        return response()->json(['data' => $this->hardening->envs()->current()]);
    }

    public function secrets(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->secrets()->inventory()]);
    }

    public function rotateSecret(Request $request): JsonResponse
    {
        $data = $request->validate(['secret_key' => ['required', 'string']]);
        $row = $this->hardening->secrets()->rotate($data['secret_key']);

        return response()->json(['data' => [
            'public_id' => $row->public_id,
            'secret_key' => $row->secret_key,
            'last_rotated_at' => $row->last_rotated_at?->toIso8601String(),
            'value' => '[NEVER_RETURNED]',
        ]]);
    }

    public function identity(): JsonResponse
    {
        return response()->json(['data' => [
            'rbac' => $this->hardening->identity()->rbacFoundation(),
            'services' => \App\Models\HardeningServiceIdentity::query()->orderBy('service_name')->get(),
            'nodes' => \App\Models\HardeningNodeIdentity::query()->orderByDesc('id')->limit(50)->get(),
        ]]);
    }

    public function registerService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_name' => ['required', 'string', 'max:96'],
            'claims' => ['nullable', 'array'],
        ]);
        $row = $this->hardening->identity()->registerService($data['service_name'], $data['claims'] ?? []);

        return response()->json(['data' => $row], 201);
    }

    public function registerNode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_label' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string'],
            'time_sync_ok' => ['nullable', 'boolean'],
        ]);
        $row = $this->hardening->identity()->registerNode(
            $data['node_label'],
            $data['platform'] ?? 'LINUX',
            $data['time_sync_ok'] ?? true,
        );

        return response()->json(['data' => $row], 201);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['purpose' => ['nullable', 'string']]);
        $payload = $this->hardening->identity()->issueMfaChallenge($request->user(), $data['purpose'] ?? 'SENSITIVE_OPS');
        unset($payload['_test_code']);

        return response()->json(['data' => $payload], 201);
    }

    public function mfaVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'nonce' => ['required', 'string'],
        ]);
        $ok = $this->hardening->identity()->verifyMfaChallenge($data['public_id'], $data['code'], $data['nonce']);

        return response()->json(['data' => ['verified' => $ok]], $ok ? 200 : 422);
    }

    public function security(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->security()->posture()]);
    }

    public function dependencies(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->deps()->runStaticChecks()]);
    }

    public function queues(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'queue_name' => ['required', 'string'],
                'job_type' => ['required', 'string'],
                'payload' => ['nullable', 'array'],
                'idempotency_key' => ['nullable', 'string'],
            ]);
            $job = $this->hardening->queues()->enqueue(
                $data['queue_name'],
                $data['job_type'],
                $data['payload'] ?? [],
                $data['idempotency_key'] ?? null,
            );

            return response()->json(['data' => $job], 201);
        }

        return response()->json(['data' => $this->hardening->queues()->stats()]);
    }

    public function dequeue(): JsonResponse
    {
        $job = $this->hardening->queues()->dequeueNext();

        return response()->json(['data' => $job]);
    }

    public function completeJob(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string'],
        ]);
        $job = \App\Models\HardeningQueueJob::query()->where('public_id', $publicId)->firstOrFail();
        $this->hardening->queues()->complete($job, (bool) $data['ok'], $data['error'] ?? null);

        return response()->json(['data' => $job->fresh()]);
    }

    public function workers(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->workers()->overview()]);
    }

    public function registerWorker(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_kind' => ['required', 'string'],
            'label' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);
        $row = $this->hardening->workers()->register($data['worker_kind'], $data['label'], $data['metadata'] ?? []);

        return response()->json(['data' => $row], 201);
    }

    public function workerAction(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string']]);
        $worker = HardeningWorkerProcess::query()->where('public_id', $publicId)->firstOrFail();
        $action = strtoupper($data['action']);
        $row = match ($action) {
            'START' => $this->hardening->workers()->start($worker),
            'HEARTBEAT' => $this->hardening->workers()->heartbeat($worker),
            'SHUTDOWN' => $this->hardening->workers()->requestGracefulShutdown($worker),
            'STOP' => $this->hardening->workers()->stop($worker),
            'RECONCILE' => $this->hardening->workers()->completeRestartReconciliation($worker),
            default => throw new \InvalidArgumentException('Unknown worker action'),
        };

        return response()->json(['data' => $row]);
    }

    public function dr(): JsonResponse
    {
        return response()->json(['data' => [
            'durability' => $this->hardening->dr()->durabilityPosture(),
            'checklist' => $this->hardening->dr()->checklist(),
        ]]);
    }

    public function backup(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->dr()->runBackup()], 201);
    }

    public function isolatedRestore(Request $request): JsonResponse
    {
        $data = $request->validate(['source_backup_path' => ['nullable', 'string']]);
        $row = $this->hardening->dr()->isolatedRestore($data['source_backup_path'] ?? null);

        return response()->json(['data' => $row], 201);
    }

    public function deploy(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->deploy()->overview()]);
    }

    public function registerDeploy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string'],
            'git_sha' => ['nullable', 'string'],
        ]);
        $row = $this->hardening->deploy()->register($request->user(), $data['version'], $data['git_sha'] ?? null);

        return response()->json(['data' => $row], 201);
    }

    public function deployAction(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'target_public_id' => ['nullable', 'string'],
        ]);
        $deploy = HardeningDeployVersion::query()->where('public_id', $publicId)->firstOrFail();
        $action = strtoupper($data['action']);
        $row = match ($action) {
            'MAINTENANCE' => $this->hardening->deploy()->enterMaintenance($deploy),
            'ACTIVATE' => $this->hardening->deploy()->activate($deploy),
            'RESUME' => $this->hardening->deploy()->markReconciledAndResume($deploy),
            'ROLLBACK' => $this->hardening->deploy()->rollback(
                $deploy,
                HardeningDeployVersion::query()->where('public_id', $data['target_public_id'] ?? '')->firstOrFail()
            ),
            default => throw new \InvalidArgumentException('Unknown deploy action'),
        };

        return response()->json(['data' => $row]);
    }

    public function failover(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->deploy()->safeFailoverCheck()]);
    }

    public function safeModes(): JsonResponse
    {
        return response()->json(['data' => \App\Models\HardeningOpsSafeMode::query()->orderByDesc('id')->limit(50)->get()]);
    }

    public function activateSafeMode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string'],
            'reason' => ['required', 'string'],
            'scope_ref' => ['nullable', 'string'],
        ]);
        $row = $this->hardening->ops()->activateSafeMode(
            $request->user(),
            $data['scope'],
            $data['reason'],
            $data['scope_ref'] ?? null,
        );

        return response()->json(['data' => $row], 201);
    }

    public function clearSafeMode(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->hardening->ops()->clearSafeMode($publicId)]);
    }

    public function capacity(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->capacity()->snapshot()]);
    }

    public function soak(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->soak()->posture()]);
    }

    public function runSoak(): JsonResponse
    {
        return response()->json(['data' => $this->hardening->soak()->runCiSafeSoak()], 201);
    }

    public function runChaos(Request $request): JsonResponse
    {
        $data = $request->validate(['scenario' => ['required', 'string']]);

        return response()->json(['data' => $this->hardening->soak()->runChaosScenario($data['scenario'])], 201);
    }

    public function refuseAiMutation(): JsonResponse
    {
        try {
            HardeningSafety::refuseAiMutation('mutate_risk');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => ['refused' => true]], 403);
        }

        return response()->json(['message' => 'unreachable'], 500);
    }

    public function refuseLiveAuto(): JsonResponse
    {
        try {
            HardeningSafety::refuseLiveAuto();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => ['refused' => true]], 403);
        }

        return response()->json(['message' => 'unreachable'], 500);
    }

    public function windowsHardening(): JsonResponse
    {
        return response()->json(['data' => [
            'checklist' => $this->hardening->identity()->nodeChecklist('WINDOWS'),
            'status' => 'CHECKLIST_ONLY_PENDING_MANUAL',
            'network' => ['segmentation' => 'RECOMMENDED', 'tls' => 'REQUIRED'],
            'time' => ['ntp' => 'REQUIRED'],
            'mt5' => 'PENDING_MANUAL_VALIDATION',
        ]]);
    }
}
