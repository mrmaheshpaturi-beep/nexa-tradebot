<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — Strategy Governance / Release Engineering / Controlled DEMO Promotion.
 * Additive only — never destroys trade history.
 * DEMO deployments only; LIVE / LIVE_AUTO do not exist here.
 * Governance never calls order_send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governed_strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('strategy_key', 80);
            $table->string('semantic_version', 32); // immutable once published
            $table->string('code_hash', 64); // sha256 of plugin code + params schema
            $table->string('config_hash', 64); // sha256 of immutable config snapshot
            $table->string('lifecycle_state', 32)->default('DRAFT');
            $table->json('configuration'); // immutable after leave DRAFT→IN_REVIEW
            $table->json('metadata')->nullable();
            $table->string('parent_version_public_id', 50)->nullable();
            $table->boolean('immutable')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['strategy_key', 'semantic_version'], 'gov_strat_semver_unique');
            $table->index(['user_id', 'lifecycle_state']);
            $table->index(['strategy_key', 'lifecycle_state']);
        });

        Schema::create('strategy_release_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->constrained('governed_strategy_versions')->cascadeOnDelete();
            $table->string('status', 32)->default('OPEN'); // OPEN|APPROVED|REJECTED|SUPERSEDED
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('checklist')->nullable();
            $table->json('risk_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('strategy_evidence_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->constrained('governed_strategy_versions')->cascadeOnDelete();
            $table->foreignId('strategy_release_candidate_id')->nullable()->constrained('strategy_release_candidates')->nullOnDelete();
            $table->string('evidence_label', 32); // DEMO|BACKTEST|WALK_FORWARD|DRY_RUN — never mixed
            $table->unsignedInteger('sample_count')->default(0);
            $table->boolean('insufficient_samples')->default(true);
            $table->boolean('can_auto_approve')->default(false); // always false if insufficient
            $table->json('phase12_analytics_refs')->nullable();
            $table->json('phase15_forward_validation_refs')->nullable();
            $table->json('metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['governed_strategy_version_id', 'evidence_label']);
        });

        Schema::create('strategy_validation_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('min_samples')->default(30);
            $table->decimal('min_win_rate', 8, 4)->nullable();
            $table->decimal('max_drawdown_pct', 8, 4)->nullable();
            $table->boolean('require_forward_validation')->default(true);
            $table->boolean('require_phase12_analytics')->default(false);
            $table->boolean('auto_approve_enabled')->default(false); // governance never AI-auto-approves
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('strategy_validation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->constrained('governed_strategy_versions')->cascadeOnDelete();
            $table->foreignId('strategy_evidence_package_id')->nullable()->constrained('strategy_evidence_packages')->nullOnDelete();
            $table->foreignId('strategy_validation_policy_id')->nullable()->constrained('strategy_validation_policies')->nullOnDelete();
            $table->string('decision', 32); // PASS|FAIL|INSUFFICIENT|HUMAN_REJECT
            $table->boolean('auto')->default(false); // auto PASS never used for deploy; auto cannot APPROVE
            $table->json('reasons')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['governed_strategy_version_id', 'decision']);
        });

        Schema::create('governance_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->constrained('governed_strategy_versions')->cascadeOnDelete();
            $table->foreignId('strategy_release_candidate_id')->nullable()->constrained('strategy_release_candidates')->nullOnDelete();
            $table->string('action', 48); // APPROVE_CANDIDATE|PROMOTE_DEMO|ROLLBACK|SUSPEND|RETIRE
            $table->string('status', 32)->default('PENDING'); // PENDING|STEP1_DONE|COMPLETED|REJECTED|EXPIRED|REVOKED
            $table->unsignedTinyInteger('required_steps')->default(2);
            $table->unsignedTinyInteger('completed_steps')->default(0);
            $table->string('bound_resource_hash', 64); // binds tokens to exact version+action+config
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['governed_strategy_version_id', 'action']);
        });

        Schema::create('governance_approval_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('governance_approval_id')->constrained('governance_approvals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step'); // 1 or 2
            $table->string('token_hash', 64); // sha256 of plaintext token — single-use
            $table->string('nonce', 64)->unique(); // replay protection
            $table->string('bound_resource_hash', 64);
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 64)->nullable();
            $table->timestamps();
            $table->unique(['governance_approval_id', 'step']);
        });

        Schema::create('strategy_deployments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->constrained('governed_strategy_versions')->cascadeOnDelete();
            $table->foreignId('governance_approval_id')->nullable()->constrained('governance_approvals')->nullOnDelete();
            $table->foreignId('automation_profile_id')->nullable()->constrained('automation_profiles')->nullOnDelete();
            $table->string('target', 32)->default('DEMO_AUTO'); // DEMO_AUTO only — no LIVE
            $table->string('status', 32)->default('PENDING'); // PENDING|ACTIVE|SUSPENDED|ROLLED_BACK|RETIRED
            $table->string('previous_deployment_public_id', 50)->nullable();
            $table->json('profile_snapshot')->nullable();
            $table->boolean('positions_preserved')->default(true);
            $table->boolean('history_preserved')->default(true);
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'target']);
        });

        Schema::create('strategy_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('left_version_public_id', 50);
            $table->string('right_version_public_id', 50);
            $table->json('diff');
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('strategy_experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->nullable()->constrained('governed_strategy_versions')->nullOnDelete();
            $table->string('lab_mode', 32)->default('ROBUSTNESS'); // ROBUSTNESS|COST_SENSITIVITY|SHADOW|AB
            $table->string('status', 32)->default('QUEUED'); // QUEUED|RUNNING|COMPLETED|FAILED|CANCELLED
            $table->json('parameters')->nullable();
            $table->json('results')->nullable();
            $table->boolean('mutates_active_config')->default(false); // always enforced false
            $table->boolean('can_deploy')->default(false); // lab never deploys
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->string('isolation_key', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('strategy_portfolios', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('DRAFT'); // DRAFT|ACTIVE|SUSPENDED|RETIRED
            $table->json('members'); // [{strategy_key, semantic_version, weight}]
            $table->string('portfolio_hash', 64);
            $table->json('conflict_resolution')->nullable(); // deterministic rules
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('strategy_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->nullable()->constrained('governed_strategy_versions')->nullOnDelete();
            $table->string('request_type', 48); // PARAM_CHANGE|RETIRE|SUSPEND|NEW_VERSION|PORTFOLIO
            $table->string('status', 32)->default('OPEN'); // OPEN|APPROVED|REJECTED|APPLIED|CANCELLED
            $table->json('proposed_change');
            $table->json('rationale')->nullable();
            $table->boolean('requires_human_approval')->default(true);
            $table->boolean('ai_may_apply')->default(false); // always false
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('governance_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->nullable()->constrained('governed_strategy_versions')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('severity', 16)->default('INFO');
            $table->boolean('immutable')->default(true);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['governed_strategy_version_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('governance_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('operation', 80);
            $table->string('resource_public_id', 50)->nullable();
            $table->json('response_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key', 'operation'], 'gov_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_idempotency_keys');
        Schema::dropIfExists('governance_events');
        Schema::dropIfExists('strategy_change_requests');
        Schema::dropIfExists('strategy_portfolios');
        Schema::dropIfExists('strategy_experiments');
        Schema::dropIfExists('strategy_comparisons');
        Schema::dropIfExists('strategy_deployments');
        Schema::dropIfExists('governance_approval_tokens');
        Schema::dropIfExists('governance_approvals');
        Schema::dropIfExists('strategy_validation_decisions');
        Schema::dropIfExists('strategy_validation_policies');
        Schema::dropIfExists('strategy_evidence_packages');
        Schema::dropIfExists('strategy_release_candidates');
        Schema::dropIfExists('governed_strategy_versions');
    }
};
