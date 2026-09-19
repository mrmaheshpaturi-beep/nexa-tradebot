<?php

namespace App\Governance;

use App\Automation\AutomationProfileService;
use App\Enums\StrategyLifecycleState;
use App\Enums\AutomationProfileStatus;
use App\Governance\Support\GovernanceSafety;
use App\Governance\Support\LifecycleGuard;
use App\Models\AutomationProfile;
use App\Models\GovernanceApproval;
use App\Models\GovernanceApprovalToken;
use App\Models\GovernanceEvent;
use App\Models\GovernanceIdempotencyKey;
use App\Models\GovernedStrategyVersion;
use App\Models\StrategyChangeRequest;
use App\Models\StrategyComparison;
use App\Models\StrategyDeployment;
use App\Models\StrategyEvidencePackage;
use App\Models\StrategyExperiment;
use App\Models\StrategyPortfolio;
use App\Models\StrategyReleaseCandidate;
use App\Models\StrategyValidationDecision;
use App\Models\StrategyValidationPolicy;
use App\Models\User;
use App\Models\ValidationSession;
use App\Services\AuditService;
use App\Strategies\StrategyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * StrategyGovernanceService — Phase 16 authority for versioning, evidence,
 * two-step human approvals, DEMO-only promotion, rollback/suspend/retire.
 *
 * NEVER calls order_send / MT5. NEVER allows AI to approve/deploy/mutate active config.
 * LIVE / LIVE_AUTO deployments do not exist.
 */
class StrategyGovernanceService
{
    public function __construct(
        private readonly StrategyRegistry $registry,
        private readonly AutomationProfileService $profiles,
        private readonly ConflictResolver $conflicts,
        private readonly AuditService $audit,
    ) {
        GovernanceSafety::assertNoLiveAuto();
    }

    /** @return array<string,mixed> */
    public function healthPayload(): array
    {
        return [
            'phase' => GovernanceSafety::PHASE,
            'status' => 'READY',
            'order_send_phase16' => GovernanceSafety::ORDER_SEND_CALL_SITES_IN_PHASE_16,
            'ai_may_approve' => GovernanceSafety::AI_MAY_APPROVE,
            'ai_may_deploy' => GovernanceSafety::AI_MAY_DEPLOY,
            'ai_may_change_active_config' => GovernanceSafety::AI_MAY_CHANGE_ACTIVE_CONFIG,
            'live_auto_exists' => GovernanceSafety::LIVE_AUTO_EXISTS,
            'live_deploy_exists' => GovernanceSafety::LIVE_DEPLOY_EXISTS,
            'allowed_deploy_targets' => GovernanceSafety::ALLOWED_DEPLOY_TARGETS,
            'lifecycle_states' => array_map(fn (StrategyLifecycleState $s) => $s->value, StrategyLifecycleState::cases()),
            'phase_10_sole_order_send' => GovernanceSafety::PHASE_10_ORDER_SEND,
            'positions_preserved_on_rollback' => GovernanceSafety::POSITIONS_PRESERVED_ON_ROLLBACK,
            'dashboard_api' => '/api/v1/governance/dashboard',
            'lab_api' => '/api/v1/governance/lab',
        ];
    }

    /** @return array<string,mixed> */
    public function dashboard(User $user): array
    {
        return [
            'health' => $this->healthPayload(),
            'versions' => GovernedStrategyVersion::query()->where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'release_candidates' => StrategyReleaseCandidate::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'approvals' => GovernanceApproval::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'deployments' => StrategyDeployment::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'experiments' => StrategyExperiment::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'portfolios' => StrategyPortfolio::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'change_requests' => StrategyChangeRequest::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'events' => GovernanceEvent::query()->where('user_id', $user->id)->latest('id')->limit(40)->get(),
            'demo_only' => true,
            'live_auto_controls' => false,
        ];
    }

    /**
     * Register an immutable semantic strategy version with code+config hashes.
     *
     * @param  array<string,mixed>  $input
     * @return array{version: GovernedStrategyVersion}
     */
    public function registerVersion(User $user, array $input, ?string $actorType = 'HUMAN'): array
    {
        GovernanceSafety::assertNotAiActor($actorType);

        $key = strtolower((string) ($input['strategy_key'] ?? ''));
        if (! $this->registry->has($key)) {
            throw ValidationException::withMessages(['strategy_key' => 'Unknown strategy plugin key.']);
        }
        $plugin = $this->registry->get($key);
        $semver = (string) ($input['semantic_version'] ?? '1.0.0');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $semver)) {
            throw ValidationException::withMessages(['semantic_version' => 'semantic_version must be MAJOR.MINOR.PATCH.']);
        }
        $config = $input['configuration'] ?? $plugin->defaultParameters();
        if (! is_array($config)) {
            throw ValidationException::withMessages(['configuration' => 'configuration must be an object.']);
        }

        $codeHash = hash('sha256', json_encode([
            'key' => $plugin->key(),
            'class' => $plugin::class,
            'defaults' => $plugin->defaultParameters(),
            'category' => $plugin->category(),
        ], JSON_THROW_ON_ERROR));
        $configHash = hash('sha256', json_encode($this->canonical($config), JSON_THROW_ON_ERROR));

        $version = GovernedStrategyVersion::query()->create([
            'user_id' => $user->id,
            'strategy_key' => $key,
            'semantic_version' => $semver,
            'code_hash' => $codeHash,
            'config_hash' => $configHash,
            'lifecycle_state' => StrategyLifecycleState::Draft,
            'configuration' => $config,
            'metadata' => $input['metadata'] ?? ['registered_by' => 'human'],
            'parent_version_public_id' => $input['parent_version_public_id'] ?? null,
            'immutable' => false,
        ]);

        $this->recordEvent($user, $version, 'VERSION_REGISTERED', [
            'semantic_version' => $semver,
            'code_hash' => $codeHash,
            'config_hash' => $configHash,
        ]);

        $this->audit->record('governance.version.register', $version, [], [
            'public_id' => $version->public_id,
            'strategy_key' => $key,
            'semantic_version' => $semver,
        ], request());

        return ['version' => $version];
    }

    public function transition(User $user, GovernedStrategyVersion $version, StrategyLifecycleState|string $to, ?string $actorType = 'HUMAN'): GovernedStrategyVersion
    {
        GovernanceSafety::assertNotAiActor($actorType);
        $this->assertOwner($user, $version);
        $toState = $to instanceof StrategyLifecycleState ? $to : StrategyLifecycleState::from(strtoupper((string) $to));
        LifecycleGuard::assertTransition($version->lifecycle_state, $toState);

        // Approvals required for RELEASE_CANDIDATE → APPROVED and APPROVED → DEPLOYED_DEMO
        if ($toState === StrategyLifecycleState::Approved || $toState === StrategyLifecycleState::DeployedDemo) {
            throw ValidationException::withMessages([
                'lifecycle' => "{$toState->value} requires two-step human approval — use approval endpoints.",
            ]);
        }

        $from = $version->lifecycle_state->value;
        $version->forceFill([
            'lifecycle_state' => $toState,
            'immutable' => ! in_array($toState, [StrategyLifecycleState::Draft], true),
            'published_at' => $toState === StrategyLifecycleState::InReview ? ($version->published_at ?? now()) : $version->published_at,
            'retired_at' => $toState === StrategyLifecycleState::Retired ? now() : $version->retired_at,
        ])->save();

        $this->recordEvent($user, $version, 'LIFECYCLE_TRANSITION', [
            'from' => $from,
            'to' => $toState->value,
        ]);

        return $version->fresh();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function openReleaseCandidate(User $user, GovernedStrategyVersion $version, array $input): StrategyReleaseCandidate
    {
        $this->assertOwner($user, $version);
        if (! in_array($version->lifecycle_state, [StrategyLifecycleState::InReview, StrategyLifecycleState::ReleaseCandidate], true)) {
            // Move to IN_REVIEW then RC if still draft
            if ($version->lifecycle_state === StrategyLifecycleState::Draft) {
                $this->transition($user, $version, StrategyLifecycleState::InReview);
                $version->refresh();
            }
        }
        if ($version->lifecycle_state === StrategyLifecycleState::InReview) {
            LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::ReleaseCandidate);
            $version->forceFill([
                'lifecycle_state' => StrategyLifecycleState::ReleaseCandidate,
                'immutable' => true,
            ])->save();
        } elseif ($version->lifecycle_state !== StrategyLifecycleState::ReleaseCandidate) {
            throw ValidationException::withMessages(['version' => 'Version must be IN_REVIEW or RELEASE_CANDIDATE.']);
        }

        $rc = StrategyReleaseCandidate::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version->id,
            'status' => 'OPEN',
            'title' => (string) ($input['title'] ?? "RC {$version->strategy_key}@{$version->semantic_version}"),
            'summary' => $input['summary'] ?? null,
            'checklist' => $input['checklist'] ?? ['evidence' => false, 'forward_validation' => false, 'human_review' => false],
            'risk_notes' => $input['risk_notes'] ?? [],
            'opened_at' => now(),
        ]);

        $this->recordEvent($user, $version, 'RELEASE_CANDIDATE_OPENED', ['rc' => $rc->public_id]);

        return $rc;
    }

    /**
     * Build evidence package linking Phase 12 analytics + Phase 15 forward validation.
     *
     * @param  array<string,mixed>  $input
     */
    public function buildEvidencePackage(User $user, GovernedStrategyVersion $version, array $input): StrategyEvidencePackage
    {
        $this->assertOwner($user, $version);
        $label = strtoupper((string) ($input['evidence_label'] ?? 'DEMO'));
        GovernanceSafety::assertEvidenceLabel($label);

        $sampleCount = (int) ($input['sample_count'] ?? 0);
        $insufficient = $sampleCount < GovernanceSafety::MIN_SAMPLES_FOR_AUTO_PASS;
        // Insufficient samples → cannot auto-approve (always). Even with enough samples, governance never AI-auto-approves deploy.
        $canAutoApprove = false;

        $fwdRefs = $input['phase15_forward_validation_refs'] ?? [];
        if (! empty($input['validation_session_public_id'])) {
            $session = ValidationSession::query()
                ->where('user_id', $user->id)
                ->where('public_id', $input['validation_session_public_id'])
                ->first();
            if ($session) {
                $fwdRefs[] = [
                    'public_id' => $session->public_id,
                    'mode' => $session->mode,
                    'evidence_label' => $session->evidence_label,
                    'funnel' => $session->funnel,
                ];
                $funnel = $session->funnel ?? [];
                $sampleCount = max($sampleCount, (int) ($funnel['executed_or_shadow'] ?? 0) + (int) ($funnel['qualified'] ?? 0));
                $insufficient = $sampleCount < GovernanceSafety::MIN_SAMPLES_FOR_AUTO_PASS;
            }
        }

        $warnings = $input['warnings'] ?? [];
        if ($insufficient) {
            $warnings[] = 'INSUFFICIENT_SAMPLES — cannot auto-approve; human may still reject.';
        }

        $pkg = StrategyEvidencePackage::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version->id,
            'strategy_release_candidate_id' => isset($input['release_candidate_id'])
                ? StrategyReleaseCandidate::query()->where('public_id', $input['release_candidate_id'])->value('id')
                : null,
            'evidence_label' => $label,
            'sample_count' => $sampleCount,
            'insufficient_samples' => $insufficient,
            'can_auto_approve' => $canAutoApprove,
            'phase12_analytics_refs' => $input['phase12_analytics_refs'] ?? [],
            'phase15_forward_validation_refs' => $fwdRefs,
            'metrics' => $input['metrics'] ?? [],
            'warnings' => $warnings,
            'payload' => $input['payload'] ?? [],
        ]);

        $this->recordEvent($user, $version, 'EVIDENCE_PACKAGE_BUILT', [
            'package' => $pkg->public_id,
            'insufficient_samples' => $insufficient,
            'can_auto_approve' => false,
        ]);

        return $pkg;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function upsertValidationPolicy(User $user, array $input): StrategyValidationPolicy
    {
        GovernanceSafety::assertNotAiActor($input['actor_type'] ?? 'HUMAN');

        return StrategyValidationPolicy::query()->create([
            'user_id' => $user->id,
            'name' => (string) ($input['name'] ?? 'Default DEMO validation policy'),
            'min_samples' => (int) ($input['min_samples'] ?? GovernanceSafety::MIN_SAMPLES_FOR_AUTO_PASS),
            'min_win_rate' => $input['min_win_rate'] ?? null,
            'max_drawdown_pct' => $input['max_drawdown_pct'] ?? null,
            'require_forward_validation' => (bool) ($input['require_forward_validation'] ?? true),
            'require_phase12_analytics' => (bool) ($input['require_phase12_analytics'] ?? false),
            'auto_approve_enabled' => false, // hard false — AI/auto never approves
            'rules' => $input['rules'] ?? [],
        ]);
    }

    public function evaluateValidation(
        User $user,
        GovernedStrategyVersion $version,
        StrategyEvidencePackage $package,
        ?StrategyValidationPolicy $policy = null,
    ): StrategyValidationDecision {
        $this->assertOwner($user, $version);
        $policy ??= StrategyValidationPolicy::query()->where('user_id', $user->id)->latest('id')->first();

        $reasons = [];
        $decision = 'PASS';
        if ($package->insufficient_samples || $package->sample_count < ($policy?->min_samples ?? GovernanceSafety::MIN_SAMPLES_FOR_AUTO_PASS)) {
            $decision = 'INSUFFICIENT';
            $reasons[] = 'Insufficient samples for automated pass; human may still reject.';
        }
        if ($policy?->require_forward_validation && empty($package->phase15_forward_validation_refs)) {
            $decision = 'FAIL';
            $reasons[] = 'Forward validation evidence required.';
        }
        if ($policy?->require_phase12_analytics && empty($package->phase12_analytics_refs)) {
            $decision = 'FAIL';
            $reasons[] = 'Phase 12 analytics evidence required.';
        }

        $row = StrategyValidationDecision::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version->id,
            'strategy_evidence_package_id' => $package->id,
            'strategy_validation_policy_id' => $policy?->id,
            'decision' => $decision,
            'auto' => true, // automated *evaluation* only — never deploy approval
            'reasons' => $reasons,
            'metrics_snapshot' => $package->metrics,
            'decided_at' => now(),
        ]);

        $this->recordEvent($user, $version, 'VALIDATION_DECISION', [
            'decision' => $decision,
            'auto_deploy_blocked' => true,
        ]);

        return $row;
    }

    /**
     * Start two-step human approval. Returns plaintext tokens once (bound, single-use).
     *
     * @return array{approval: GovernanceApproval, tokens: array{step1: string, step2: string}}
     */
    public function startApproval(User $user, GovernedStrategyVersion $version, string $action, ?string $actorType = 'HUMAN'): array
    {
        GovernanceSafety::assertNotAiActor($actorType);
        $this->assertOwner($user, $version);

        $action = strtoupper($action);
        $allowed = ['APPROVE_CANDIDATE', 'PROMOTE_DEMO', 'ROLLBACK', 'SUSPEND', 'RETIRE'];
        if (! in_array($action, $allowed, true)) {
            throw ValidationException::withMessages(['action' => 'Invalid approval action.']);
        }
        if ($action === 'APPROVE_CANDIDATE' && $version->lifecycle_state !== StrategyLifecycleState::ReleaseCandidate) {
            throw ValidationException::withMessages(['action' => 'APPROVE_CANDIDATE requires RELEASE_CANDIDATE state.']);
        }
        if ($action === 'PROMOTE_DEMO' && $version->lifecycle_state !== StrategyLifecycleState::Approved) {
            throw ValidationException::withMessages(['action' => 'PROMOTE_DEMO requires APPROVED state.']);
        }

        $bound = hash('sha256', json_encode([
            'version' => $version->public_id,
            'code_hash' => $version->code_hash,
            'config_hash' => $version->config_hash,
            'action' => $action,
            'lifecycle' => $version->lifecycle_state->value,
        ], JSON_THROW_ON_ERROR));

        $approval = GovernanceApproval::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version->id,
            'action' => $action,
            'status' => 'PENDING',
            'required_steps' => 2,
            'completed_steps' => 0,
            'bound_resource_hash' => $bound,
            'expires_at' => now()->addSeconds(GovernanceSafety::DEFAULT_APPROVAL_TTL_SECONDS),
            'meta' => ['actor_type' => 'HUMAN'],
        ]);

        $plain = [];
        foreach ([1, 2] as $step) {
            $token = Str::random(48);
            $nonce = (string) Str::uuid();
            GovernanceApprovalToken::query()->create([
                'governance_approval_id' => $approval->id,
                'user_id' => $user->id,
                'step' => $step,
                'token_hash' => hash('sha256', $token),
                'nonce' => $nonce,
                'bound_resource_hash' => $bound,
                'used' => false,
                'expires_at' => $approval->expires_at,
            ]);
            $plain["step{$step}"] = $token;
            $plain["step{$step}_nonce"] = $nonce;
        }

        $this->recordEvent($user, $version, 'APPROVAL_STARTED', [
            'approval' => $approval->public_id,
            'action' => $action,
        ]);

        return ['approval' => $approval, 'tokens' => $plain];
    }

    /**
     * Consume a single-use bound token for step 1 or 2. Completes action on step 2.
     *
     * @return array<string,mixed>
     */
    public function consumeApprovalStep(
        User $user,
        GovernanceApproval $approval,
        int $step,
        string $token,
        string $nonce,
        ?string $actorType = 'HUMAN',
        ?string $idempotencyKey = null,
    ): array {
        GovernanceSafety::assertNotAiActor($actorType);

        if ($idempotencyKey) {
            $existing = GovernanceIdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', "approval_step_{$step}")
                ->first();
            if ($existing?->response_snapshot) {
                return $existing->response_snapshot;
            }
        }

        return DB::transaction(function () use ($user, $approval, $step, $token, $nonce, $idempotencyKey): array {
            $approval = GovernanceApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if ($approval->user_id !== $user->id) {
                throw ValidationException::withMessages(['approval' => 'Not found.']);
            }
            if ($approval->expires_at->isPast()) {
                $approval->forceFill(['status' => 'EXPIRED'])->save();
                throw ValidationException::withMessages(['approval' => 'Approval expired (staleness check).']);
            }
            if (in_array($approval->status, ['COMPLETED', 'REJECTED', 'REVOKED', 'EXPIRED'], true)) {
                throw ValidationException::withMessages(['approval' => "Approval is {$approval->status}."]);
            }

            $tokenRow = GovernanceApprovalToken::query()
                ->where('governance_approval_id', $approval->id)
                ->where('step', $step)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tokenRow->used) {
                throw ValidationException::withMessages(['token' => 'Token already used (replay protection).']);
            }
            if ($tokenRow->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'Token expired.']);
            }
            if (! hash_equals($tokenRow->token_hash, hash('sha256', $token))) {
                throw ValidationException::withMessages(['token' => 'Invalid token.']);
            }
            if (! hash_equals($tokenRow->nonce, $nonce)) {
                throw ValidationException::withMessages(['nonce' => 'Nonce mismatch (replay protection).']);
            }
            if (! hash_equals($tokenRow->bound_resource_hash, $approval->bound_resource_hash)) {
                throw ValidationException::withMessages(['token' => 'Token not bound to this approval resource.']);
            }

            // Staleness: version hashes must still match binding
            $version = GovernedStrategyVersion::query()->whereKey($approval->governed_strategy_version_id)->lockForUpdate()->firstOrFail();
            $currentBound = hash('sha256', json_encode([
                'version' => $version->public_id,
                'code_hash' => $version->code_hash,
                'config_hash' => $version->config_hash,
                'action' => $approval->action,
                'lifecycle' => match ($approval->action) {
                    'APPROVE_CANDIDATE' => StrategyLifecycleState::ReleaseCandidate->value,
                    'PROMOTE_DEMO' => StrategyLifecycleState::Approved->value,
                    default => $version->lifecycle_state->value,
                },
            ], JSON_THROW_ON_ERROR));
            // For SUSPEND/ROLLBACK/RETIRE allow current lifecycle in binding check via stored hash equality only
            if ($approval->action === 'APPROVE_CANDIDATE' || $approval->action === 'PROMOTE_DEMO') {
                if (! hash_equals($approval->bound_resource_hash, $currentBound)) {
                    $approval->forceFill(['status' => 'EXPIRED'])->save();
                    throw ValidationException::withMessages(['approval' => 'Resource changed since approval started (staleness).']);
                }
            }

            $tokenRow->forceFill([
                'used' => true,
                'used_at' => now(),
                'used_ip' => request()?->ip(),
            ])->save();

            $approval->completed_steps = (int) $approval->completed_steps + 1;
            if ($step === 1) {
                $approval->status = 'STEP1_DONE';
                $approval->save();
                $result = ['approval' => $approval->fresh(), 'completed' => false, 'step' => 1];
            } else {
                if ($approval->completed_steps < 2 || $approval->status !== 'STEP1_DONE') {
                    throw ValidationException::withMessages(['step' => 'Step 1 must complete before step 2.']);
                }
                $approval->status = 'COMPLETED';
                $approval->completed_at = now();
                $approval->save();
                $result = $this->finalizeApprovedAction($user, $approval, $version);
                $result['completed'] = true;
                $result['step'] = 2;
            }

            if ($idempotencyKey) {
                GovernanceIdempotencyKey::query()->create([
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'operation' => "approval_step_{$step}",
                    'resource_public_id' => $approval->public_id,
                    'response_snapshot' => $this->serializeResult($result),
                ]);
            }

            return $result;
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function finalizeApprovedAction(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        return match ($approval->action) {
            'APPROVE_CANDIDATE' => $this->finalizeApproveCandidate($user, $approval, $version),
            'PROMOTE_DEMO' => $this->finalizePromoteDemo($user, $approval, $version),
            'ROLLBACK' => $this->finalizeRollback($user, $approval, $version),
            'SUSPEND' => $this->finalizeSuspend($user, $approval, $version),
            'RETIRE' => $this->finalizeRetire($user, $approval, $version),
            default => throw ValidationException::withMessages(['action' => 'Unknown action.']),
        };
    }

    /** @return array<string,mixed> */
    private function finalizeApproveCandidate(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::Approved);
        $version->forceFill([
            'lifecycle_state' => StrategyLifecycleState::Approved,
            'immutable' => true,
        ])->save();
        StrategyReleaseCandidate::query()
            ->where('governed_strategy_version_id', $version->id)
            ->where('status', 'OPEN')
            ->update(['status' => 'APPROVED', 'closed_at' => now()]);
        $this->recordEvent($user, $version, 'CANDIDATE_APPROVED', ['approval' => $approval->public_id]);

        return ['approval' => $approval->fresh(), 'version' => $version->fresh()];
    }

    /**
     * DEMO-only promotion — updates AutomationProfile strategy matrix; never LIVE.
     *
     * @return array<string,mixed>
     */
    private function finalizePromoteDemo(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        GovernanceSafety::assertDeployTargetAllowed('DEMO_AUTO');
        LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::DeployedDemo);

        $profile = AutomationProfile::query()
            ->where('user_id', $user->id)
            ->where('status', AutomationProfileStatus::Active)
            ->latest('id')
            ->first();

        if (! $profile) {
            // Create + validate + activate a DEMO profile bound to this version
            $created = $this->profiles->create($user, [
                'name' => "Governed {$version->strategy_key}@{$version->semantic_version}",
                'strategy_matrix' => [[
                    'symbol' => 'EURUSD',
                    'timeframe' => 'H1',
                    'strategy_key' => $version->strategy_key,
                    'strategy_version' => $version->semantic_version,
                    'governed_version_public_id' => $version->public_id,
                    'code_hash' => $version->code_hash,
                    'config_hash' => $version->config_hash,
                ]],
            ]);
            $this->profiles->validate($created);
            $profile = $this->profiles->activate($created);
        } else {
            // Revise requires non-ACTIVE — temporarily demote, revise, re-validate, activate
            $matrix = $profile->strategy_matrix ?? [];
            $replaced = false;
            foreach ($matrix as &$row) {
                if (($row['strategy_key'] ?? '') === $version->strategy_key) {
                    $row['strategy_version'] = $version->semantic_version;
                    $row['governed_version_public_id'] = $version->public_id;
                    $row['code_hash'] = $version->code_hash;
                    $row['config_hash'] = $version->config_hash;
                    $replaced = true;
                }
            }
            unset($row);
            if (! $replaced) {
                $matrix[] = [
                    'symbol' => 'EURUSD',
                    'timeframe' => 'H1',
                    'strategy_key' => $version->strategy_key,
                    'strategy_version' => $version->semantic_version,
                    'governed_version_public_id' => $version->public_id,
                    'code_hash' => $version->code_hash,
                    'config_hash' => $version->config_hash,
                ];
            }
            $profile->forceFill(['status' => AutomationProfileStatus::Validated])->save();
            $profile = $this->profiles->revise($profile, ['strategy_matrix' => $matrix]);
            $this->profiles->validate($profile);
            $profile = $this->profiles->activate($profile);
        }

        // Suspend prior active deployments for same strategy_key (positions preserved)
        StrategyDeployment::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereHas('version', fn ($q) => $q->where('strategy_key', $version->strategy_key))
            ->update([
                'status' => 'SUSPENDED',
                'suspended_at' => now(),
                'positions_preserved' => true,
                'history_preserved' => true,
            ]);

        $deployment = StrategyDeployment::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version->id,
            'governance_approval_id' => $approval->id,
            'automation_profile_id' => $profile->id,
            'target' => 'DEMO_AUTO',
            'status' => 'ACTIVE',
            'profile_snapshot' => [
                'public_id' => $profile->public_id,
                'config_hash' => $profile->config_hash,
                'strategy_matrix' => $profile->strategy_matrix,
            ],
            'positions_preserved' => true,
            'history_preserved' => true,
            'deployed_at' => now(),
            'meta' => ['phase14_integrated' => true, 'live' => false],
        ]);

        $version->forceFill(['lifecycle_state' => StrategyLifecycleState::DeployedDemo])->save();
        $this->recordEvent($user, $version, 'PROMOTED_DEMO', [
            'deployment' => $deployment->public_id,
            'profile' => $profile->public_id,
            'target' => 'DEMO_AUTO',
        ]);

        $this->audit->record('governance.promote.demo', $deployment, [], [
            'deployment' => $deployment->public_id,
            'version' => $version->public_id,
        ], request());

        return [
            'approval' => $approval->fresh(),
            'version' => $version->fresh(),
            'deployment' => $deployment,
            'automation_profile' => $profile->fresh(),
        ];
    }

    /** @return array<string,mixed> */
    private function finalizeRollback(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::RolledBack);

        $active = StrategyDeployment::query()
            ->where('user_id', $user->id)
            ->where('governed_strategy_version_id', $version->id)
            ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
            ->latest('id')
            ->first();

        if ($active) {
            $active->forceFill([
                'status' => 'ROLLED_BACK',
                'rolled_back_at' => now(),
                'positions_preserved' => true,
                'history_preserved' => true,
            ])->save();
        }

        // Do NOT abandon Phase 11 managed positions — only stop routing new entries via profile demotion
        if ($active?->automation_profile_id) {
            $profile = AutomationProfile::query()->find($active->automation_profile_id);
            if ($profile && $profile->status === AutomationProfileStatus::Active) {
                $profile->forceFill(['status' => AutomationProfileStatus::Validated])->save();
            }
        }

        $version->forceFill(['lifecycle_state' => StrategyLifecycleState::RolledBack])->save();
        $this->recordEvent($user, $version, 'ROLLED_BACK', [
            'deployment' => $active?->public_id,
            'positions_preserved' => true,
            'phase11_positions_abandoned' => false,
        ]);

        return ['approval' => $approval->fresh(), 'version' => $version->fresh(), 'deployment' => $active?->fresh()];
    }

    /** @return array<string,mixed> */
    private function finalizeSuspend(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::Suspended);
        StrategyDeployment::query()
            ->where('user_id', $user->id)
            ->where('governed_strategy_version_id', $version->id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'SUSPENDED',
                'suspended_at' => now(),
                'positions_preserved' => true,
                'history_preserved' => true,
            ]);
        $version->forceFill(['lifecycle_state' => StrategyLifecycleState::Suspended])->save();
        $this->recordEvent($user, $version, 'SUSPENDED', ['positions_preserved' => true]);

        return ['approval' => $approval->fresh(), 'version' => $version->fresh()];
    }

    /** @return array<string,mixed> */
    private function finalizeRetire(User $user, GovernanceApproval $approval, GovernedStrategyVersion $version): array
    {
        LifecycleGuard::assertTransition($version->lifecycle_state, StrategyLifecycleState::Retired);
        StrategyDeployment::query()
            ->where('user_id', $user->id)
            ->where('governed_strategy_version_id', $version->id)
            ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
            ->update([
                'status' => 'RETIRED',
                'retired_at' => now(),
                'positions_preserved' => true,
                'history_preserved' => true,
            ]);
        $version->forceFill([
            'lifecycle_state' => StrategyLifecycleState::Retired,
            'retired_at' => now(),
        ])->save();
        $this->recordEvent($user, $version, 'RETIRED', [
            'history_preserved' => true,
            'positions_preserved' => true,
        ]);

        return ['approval' => $approval->fresh(), 'version' => $version->fresh()];
    }

    public function compare(User $user, GovernedStrategyVersion $left, GovernedStrategyVersion $right): StrategyComparison
    {
        $diff = [
            'strategy_key' => ['left' => $left->strategy_key, 'right' => $right->strategy_key, 'changed' => $left->strategy_key !== $right->strategy_key],
            'semantic_version' => ['left' => $left->semantic_version, 'right' => $right->semantic_version, 'changed' => $left->semantic_version !== $right->semantic_version],
            'code_hash' => ['left' => $left->code_hash, 'right' => $right->code_hash, 'changed' => $left->code_hash !== $right->code_hash],
            'config_hash' => ['left' => $left->config_hash, 'right' => $right->config_hash, 'changed' => $left->config_hash !== $right->config_hash],
            'configuration' => $this->configDiff($left->configuration ?? [], $right->configuration ?? []),
            'lifecycle' => ['left' => $left->lifecycle_state->value, 'right' => $right->lifecycle_state->value],
        ];

        return StrategyComparison::query()->create([
            'user_id' => $user->id,
            'left_version_public_id' => $left->public_id,
            'right_version_public_id' => $right->public_id,
            'diff' => $diff,
            'summary' => [
                'config_changed' => $diff['config_hash']['changed'],
                'code_changed' => $diff['code_hash']['changed'],
            ],
        ]);
    }

    /**
     * Strategy Lab — isolated experiments; never mutates active config; never deploys.
     *
     * @param  array<string,mixed>  $input
     */
    public function runLabExperiment(User $user, array $input, ?string $actorType = 'HUMAN'): StrategyExperiment
    {
        GovernanceSafety::assertNotAiActor($actorType); // AI may request analysis but cannot apply

        $mode = strtoupper((string) ($input['lab_mode'] ?? 'ROBUSTNESS'));
        if (! in_array($mode, ['ROBUSTNESS', 'COST_SENSITIVITY', 'SHADOW', 'AB'], true)) {
            throw ValidationException::withMessages(['lab_mode' => 'Invalid lab mode.']);
        }
        $timeout = min(120, max(1, (int) ($input['timeout_seconds'] ?? GovernanceSafety::DEFAULT_LAB_TIMEOUT_SECONDS)));
        $isolation = 'lab:'.Str::uuid();

        $version = null;
        if (! empty($input['version_public_id'])) {
            $version = GovernedStrategyVersion::query()
                ->where('user_id', $user->id)
                ->where('public_id', $input['version_public_id'])
                ->firstOrFail();
        }

        $exp = StrategyExperiment::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version?->id,
            'lab_mode' => $mode,
            'status' => 'RUNNING',
            'parameters' => $input['parameters'] ?? [],
            'mutates_active_config' => false,
            'can_deploy' => false,
            'timeout_seconds' => $timeout,
            'isolation_key' => $isolation,
            'started_at' => now(),
        ]);

        $started = microtime(true);
        $results = match ($mode) {
            'ROBUSTNESS' => $this->labRobustness($input['parameters'] ?? []),
            'COST_SENSITIVITY' => $this->labCostSensitivity($input['parameters'] ?? []),
            'SHADOW' => ['shadow' => true, 'broker_touched' => false, 'note' => 'Shadow only — no deploy'],
            'AB' => ['variants' => $input['parameters']['variants'] ?? ['A', 'B'], 'winner' => null, 'auto_promote' => false],
            default => [],
        };
        $elapsedMs = (int) ((microtime(true) - $started) * 1000);
        if ($elapsedMs > $timeout * 1000) {
            $exp->forceFill([
                'status' => 'FAILED',
                'results' => ['error' => 'TIMEOUT', 'isolation_key' => $isolation],
                'finished_at' => now(),
            ])->save();
        } else {
            $exp->forceFill([
                'status' => 'COMPLETED',
                'results' => array_merge($results, [
                    'elapsed_ms' => $elapsedMs,
                    'mutates_active_config' => false,
                    'can_deploy' => false,
                    'order_send' => 0,
                    'isolation_key' => $isolation,
                ]),
                'finished_at' => now(),
            ])->save();
        }

        $this->recordEvent($user, $version, 'LAB_EXPERIMENT', [
            'experiment' => $exp->public_id,
            'mode' => $mode,
            'can_deploy' => false,
        ]);

        return $exp->fresh();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function createPortfolio(User $user, array $input): StrategyPortfolio
    {
        $members = $input['members'] ?? [];
        if (! is_array($members) || $members === []) {
            throw ValidationException::withMessages(['members' => 'Portfolio members required.']);
        }
        $resolved = $this->conflicts->resolve($members);
        $hash = hash('sha256', json_encode($this->canonical($resolved['members']), JSON_THROW_ON_ERROR));

        return StrategyPortfolio::query()->create([
            'user_id' => $user->id,
            'name' => (string) ($input['name'] ?? 'Strategy Portfolio'),
            'version' => 1,
            'status' => 'DRAFT',
            'members' => $resolved['members'],
            'portfolio_hash' => $hash,
            'conflict_resolution' => $resolved['resolution'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function createChangeRequest(User $user, array $input, ?string $actorType = 'HUMAN'): StrategyChangeRequest
    {
        // AI may file a request but cannot apply it
        $aiMayApply = false;
        if (in_array(strtoupper((string) $actorType), ['AI', 'AGENT', 'LLM', 'INTELLIGENCE'], true)) {
            $aiMayApply = false;
        }

        $versionId = null;
        if (! empty($input['version_public_id'])) {
            $versionId = GovernedStrategyVersion::query()
                ->where('user_id', $user->id)
                ->where('public_id', $input['version_public_id'])
                ->value('id');
        }

        return StrategyChangeRequest::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $versionId,
            'request_type' => strtoupper((string) ($input['request_type'] ?? 'PARAM_CHANGE')),
            'status' => 'OPEN',
            'proposed_change' => $input['proposed_change'] ?? [],
            'rationale' => $input['rationale'] ?? [],
            'requires_human_approval' => true,
            'ai_may_apply' => $aiMayApply,
        ]);
    }

    public function rejectChangeRequest(User $user, StrategyChangeRequest $cr): StrategyChangeRequest
    {
        if ($cr->user_id !== $user->id) {
            throw ValidationException::withMessages(['change_request' => 'Not found.']);
        }
        $cr->forceFill(['status' => 'REJECTED'])->save();

        return $cr->fresh();
    }

    public function refuseLiveDeploy(): array
    {
        return [
            'allowed' => false,
            'live_deploy_exists' => false,
            'live_auto_exists' => false,
            'message' => 'LIVE / LIVE_AUTO deployments do not exist. DEMO_AUTO only after two-step human approval.',
        ];
    }

    public function refuseAiApprove(): array
    {
        return [
            'allowed' => false,
            'ai_may_approve' => false,
            'ai_may_deploy' => false,
            'ai_may_change_active_config' => false,
            'message' => 'AI cannot approve, deploy, or change active governance config.',
        ];
    }

    /** @param  array<string,mixed>  $payload */
    private function recordEvent(User $user, ?GovernedStrategyVersion $version, string $type, array $payload = []): void
    {
        GovernanceEvent::query()->create([
            'user_id' => $user->id,
            'governed_strategy_version_id' => $version?->id,
            'event_type' => $type,
            'severity' => 'AUDIT',
            'immutable' => true,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function assertOwner(User $user, GovernedStrategyVersion $version): void
    {
        if ($version->user_id !== $user->id) {
            throw ValidationException::withMessages(['version' => 'Not found.']);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonical($v);
            }
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>
     */
    private function configDiff(array $a, array $b): array
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $out = [];
        foreach ($keys as $key) {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if ($left !== $right) {
                $out[$key] = ['left' => $left, 'right' => $right];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function labRobustness(array $params): array
    {
        $shocks = $params['shocks'] ?? [0.5, 1.0, 1.5, 2.0];
        $scores = [];
        foreach ($shocks as $s) {
            $scores[] = ['shock' => $s, 'stability' => round(1 / (1 + (float) $s), 4)];
        }

        return ['mode' => 'ROBUSTNESS', 'scores' => $scores, 'promotes' => false];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function labCostSensitivity(array $params): array
    {
        $spreads = $params['spreads'] ?? [0.5, 1.0, 2.0, 3.0];
        $rows = [];
        foreach ($spreads as $sp) {
            $rows[] = ['spread' => $sp, 'net_edge' => round(max(0, 2.0 - (float) $sp), 4)];
        }

        return ['mode' => 'COST_SENSITIVITY', 'rows' => $rows, 'promotes' => false];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function serializeResult(array $result): array
    {
        $out = [];
        foreach ($result as $k => $v) {
            if ($v instanceof \Illuminate\Database\Eloquent\Model) {
                $out[$k] = $v->toArray();
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
