<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardening_secret_inventory', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('secret_key', 120)->unique();
            $table->string('category', 64)->index();
            $table->string('provider', 64)->default('ENV');
            $table->string('storage', 64)->default('SERVER_SIDE_ONLY');
            $table->boolean('frontend_forbidden')->default(true);
            $table->boolean('git_forbidden')->default(true);
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('rotation_due_at')->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_service_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('service_name', 96)->unique();
            $table->string('identity_kind', 32)->default('SERVICE');
            $table->string('fingerprint', 128)->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->json('claims')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_node_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('node_label', 120)->index();
            $table->string('node_fingerprint', 128)->unique();
            $table->string('platform', 64)->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->boolean('time_sync_ok')->default(false);
            $table->boolean('tls_required')->default(true);
            $table->json('hardening_checklist')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_mfa_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 64)->index();
            $table->string('challenge_hash', 128);
            $table->string('status', 32)->default('PENDING')->index();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('nonce', 64)->unique();
            $table->timestamps();
        });

        Schema::create('hardening_queue_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('queue_name', 64)->index();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->string('job_type', 96)->index();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('status', 32)->default('PENDING')->index();
            $table->json('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->text('error')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'id']);
        });

        Schema::create('hardening_dlq_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('source_job_id')->nullable()->constrained('hardening_queue_jobs')->nullOnDelete();
            $table->string('queue_name', 64)->index();
            $table->string('job_type', 96)->index();
            $table->string('idempotency_key', 128)->nullable()->index();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->string('status', 32)->default('DEAD')->index();
            $table->timestamp('dead_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('hardening_worker_processes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('worker_kind', 64)->index();
            $table->string('label', 120);
            $table->string('status', 32)->default('STOPPED')->index();
            $table->boolean('graceful_shutdown')->default(false);
            $table->boolean('restart_reconcile_required')->default(true);
            $table->boolean('reconcile_completed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_deploy_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('version', 64)->index();
            $table->string('git_sha', 64)->nullable();
            $table->string('status', 32)->default('REGISTERED')->index();
            $table->boolean('maintenance_mode')->default(false);
            $table->boolean('trading_paused')->default(false);
            $table->boolean('reconcile_before_resume')->default(true);
            $table->json('checklist')->nullable();
            $table->json('rollback_of')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_ops_safe_modes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('scope', 64)->index();
            $table->string('scope_ref', 120)->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->string('reason', 255);
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at');
            $table->timestamp('cleared_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_isolated_restores', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('source_backup_path')->nullable();
            $table->string('status', 32)->default('PENDING')->index();
            $table->boolean('isolated')->default(true);
            $table->boolean('verified')->default(false);
            $table->boolean('reconcile_required_before_trading')->default(true);
            $table->boolean('auto_resume_forbidden')->default(true);
            $table->json('manifest')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hardening_config_validations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('app_environment', 32)->index();
            $table->string('broker_trade_mode', 32)->index();
            $table->boolean('ok')->default(false);
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('safe_defaults')->nullable();
            $table->timestamp('validated_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('hardening_replay_nonces', function (Blueprint $table): void {
            $table->id();
            $table->string('nonce', 96)->unique();
            $table->string('purpose', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardening_replay_nonces');
        Schema::dropIfExists('hardening_config_validations');
        Schema::dropIfExists('hardening_isolated_restores');
        Schema::dropIfExists('hardening_ops_safe_modes');
        Schema::dropIfExists('hardening_deploy_versions');
        Schema::dropIfExists('hardening_worker_processes');
        Schema::dropIfExists('hardening_dlq_jobs');
        Schema::dropIfExists('hardening_queue_jobs');
        Schema::dropIfExists('hardening_mfa_challenges');
        Schema::dropIfExists('hardening_node_identities');
        Schema::dropIfExists('hardening_service_identities');
        Schema::dropIfExists('hardening_secret_inventory');
    }
};
