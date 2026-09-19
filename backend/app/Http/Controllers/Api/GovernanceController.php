<?php

namespace App\Http\Controllers\Api;

use App\Enums\StrategyLifecycleState;
use App\Governance\StrategyGovernanceService;
use App\Governance\Support\GovernanceSafety;
use App\Http\Controllers\Controller;
use App\Models\GovernanceApproval;
use App\Models\GovernedStrategyVersion;
use App\Models\StrategyChangeRequest;
use App\Models\StrategyEvidencePackage;
use App\Models\StrategyValidationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GovernanceController extends Controller
{
    public function __construct(private readonly StrategyGovernanceService $gov) {}

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->gov->healthPayload()]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->gov->dashboard($request->user())]);
    }

    public function versions(Request $request): JsonResponse
    {
        return response()->json(['data' => GovernedStrategyVersion::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(100)
            ->get()]);
    }

    public function registerVersion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strategy_key' => ['required', 'string', 'max:80'],
            'semantic_version' => ['required', 'string', 'max:32'],
            'configuration' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'parent_version_public_id' => ['nullable', 'string', 'max:50'],
            'actor_type' => ['nullable', 'string', 'max:32'],
        ]);
        try {
            $result = $this->gov->registerVersion($request->user(), $data, $data['actor_type'] ?? 'HUMAN');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->gov->refuseAiApprove()], 403);
        }

        return response()->json(['data' => $result['version']], 201);
    }

    public function showVersion(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);

        return response()->json(['data' => $version->load(['releaseCandidates', 'evidencePackages', 'deployments'])]);
    }

    public function transition(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);
        $data = $request->validate([
            'to' => ['required', 'string'],
            'actor_type' => ['nullable', 'string', 'max:32'],
        ]);
        try {
            $updated = $this->gov->transition($request->user(), $version, $data['to'], $data['actor_type'] ?? 'HUMAN');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $updated]);
    }

    public function openReleaseCandidate(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'summary' => ['nullable', 'string'],
            'checklist' => ['nullable', 'array'],
            'risk_notes' => ['nullable', 'array'],
        ]);
        $rc = $this->gov->openReleaseCandidate($request->user(), $version, $data);

        return response()->json(['data' => $rc], 201);
    }

    public function buildEvidence(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);
        $data = $request->validate([
            'evidence_label' => ['required', 'string'],
            'sample_count' => ['nullable', 'integer', 'min:0'],
            'phase12_analytics_refs' => ['nullable', 'array'],
            'phase15_forward_validation_refs' => ['nullable', 'array'],
            'validation_session_public_id' => ['nullable', 'string'],
            'release_candidate_id' => ['nullable', 'string'],
            'metrics' => ['nullable', 'array'],
            'warnings' => ['nullable', 'array'],
            'payload' => ['nullable', 'array'],
        ]);
        $pkg = $this->gov->buildEvidencePackage($request->user(), $version, $data);

        return response()->json(['data' => $pkg], 201);
    }

    public function createPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'min_samples' => ['nullable', 'integer', 'min:1'],
            'min_win_rate' => ['nullable', 'numeric'],
            'max_drawdown_pct' => ['nullable', 'numeric'],
            'require_forward_validation' => ['nullable', 'boolean'],
            'require_phase12_analytics' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string'],
        ]);
        try {
            $policy = $this->gov->upsertValidationPolicy($request->user(), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $policy], 201);
    }

    public function evaluateValidation(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);
        $data = $request->validate([
            'evidence_package_public_id' => ['required', 'string'],
            'policy_public_id' => ['nullable', 'string'],
        ]);
        $pkg = StrategyEvidencePackage::query()
            ->where('user_id', $request->user()->id)
            ->where('public_id', $data['evidence_package_public_id'])
            ->firstOrFail();
        $policy = null;
        if (! empty($data['policy_public_id'])) {
            $policy = StrategyValidationPolicy::query()
                ->where('user_id', $request->user()->id)
                ->where('public_id', $data['policy_public_id'])
                ->firstOrFail();
        }
        $decision = $this->gov->evaluateValidation($request->user(), $version, $pkg, $policy);

        return response()->json(['data' => $decision]);
    }

    public function startApproval(Request $request, GovernedStrategyVersion $version): JsonResponse
    {
        $this->assertOwner($request, $version);
        $data = $request->validate([
            'action' => ['required', 'string'],
            'actor_type' => ['nullable', 'string'],
        ]);
        try {
            $result = $this->gov->startApproval($request->user(), $version, $data['action'], $data['actor_type'] ?? 'HUMAN');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->gov->refuseAiApprove()], 403);
        }

        return response()->json(['data' => $result], 201);
    }

    public function approvalStep(Request $request, GovernanceApproval $approval): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'in:1,2'],
            'token' => ['required', 'string'],
            'nonce' => ['required', 'string'],
            'actor_type' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        try {
            $result = $this->gov->consumeApprovalStep(
                $request->user(),
                $approval,
                (int) $data['step'],
                $data['token'],
                $data['nonce'],
                $data['actor_type'] ?? 'HUMAN',
                $data['idempotency_key'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $result]);
    }

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'left_public_id' => ['required', 'string'],
            'right_public_id' => ['required', 'string'],
        ]);
        $left = GovernedStrategyVersion::query()->where('user_id', $request->user()->id)->where('public_id', $data['left_public_id'])->firstOrFail();
        $right = GovernedStrategyVersion::query()->where('user_id', $request->user()->id)->where('public_id', $data['right_public_id'])->firstOrFail();
        $cmp = $this->gov->compare($request->user(), $left, $right);

        return response()->json(['data' => $cmp], 201);
    }

    public function lab(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'phase' => GovernanceSafety::PHASE,
            'modes' => ['ROBUSTNESS', 'COST_SENSITIVITY', 'SHADOW', 'AB'],
            'mutates_active_config' => false,
            'can_deploy' => false,
            'order_send' => 0,
            'ai_may_apply' => false,
            'demo_only' => true,
            'live_auto_controls' => false,
            'timeout_default' => GovernanceSafety::DEFAULT_LAB_TIMEOUT_SECONDS,
            'experiments' => \App\Models\StrategyExperiment::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(30)
                ->get(),
        ]]);
    }

    public function runLab(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lab_mode' => ['required', 'string'],
            'version_public_id' => ['nullable', 'string'],
            'parameters' => ['nullable', 'array'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'actor_type' => ['nullable', 'string'],
        ]);
        try {
            $exp = $this->gov->runLabExperiment($request->user(), $data, $data['actor_type'] ?? 'HUMAN');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $exp], 201);
    }

    public function createPortfolio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'members' => ['required', 'array', 'min:1'],
        ]);
        $pf = $this->gov->createPortfolio($request->user(), $data);

        return response()->json(['data' => $pf], 201);
    }

    public function createChangeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type' => ['nullable', 'string'],
            'version_public_id' => ['nullable', 'string'],
            'proposed_change' => ['nullable', 'array'],
            'rationale' => ['nullable', 'array'],
            'actor_type' => ['nullable', 'string'],
        ]);
        $cr = $this->gov->createChangeRequest($request->user(), $data, $data['actor_type'] ?? 'HUMAN');

        return response()->json(['data' => $cr], 201);
    }

    public function rejectChangeRequest(Request $request, StrategyChangeRequest $changeRequest): JsonResponse
    {
        $cr = $this->gov->rejectChangeRequest($request->user(), $changeRequest);

        return response()->json(['data' => $cr]);
    }

    public function refuseLiveDeploy(): JsonResponse
    {
        return response()->json(['data' => $this->gov->refuseLiveDeploy()], 403);
    }

    public function refuseAiApprove(): JsonResponse
    {
        return response()->json(['data' => $this->gov->refuseAiApprove()], 403);
    }

    public function lifecycleDoc(): JsonResponse
    {
        $doc = [];
        foreach (StrategyLifecycleState::cases() as $state) {
            $doc[$state->value] = array_map(fn (StrategyLifecycleState $s) => $s->value, $state->allowedNext());
        }

        return response()->json(['data' => [
            'allowed_transitions' => $doc,
            'illegal_jumps_rejected' => true,
            'approve_and_promote_require_two_step_human' => true,
        ]]);
    }

    private function assertOwner(Request $request, GovernedStrategyVersion $version): void
    {
        if ($version->user_id !== $request->user()->id) {
            throw ValidationException::withMessages(['version' => 'Not found.']);
        }
    }
}
