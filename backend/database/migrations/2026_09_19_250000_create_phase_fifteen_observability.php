<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('name', 120)->index();
            $table->string('category', 64)->index();
            $table->string('evidence_label', 32)->index();
            $table->decimal('value', 20, 8);
            $table->string('unit', 32)->nullable();
            $table->json('labels')->nullable();
            $table->boolean('insufficient_sample')->default(false);
            $table->unsignedInteger('sample_count')->default(1);
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('system_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('overall_status', 32)->index();
            $table->string('trading_readiness', 32)->index();
            $table->json('dependencies');
            $table->json('checks')->nullable();
            $table->boolean('new_entries_blocked')->default(false);
            $table->string('block_reason', 255)->nullable();
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('fingerprint', 128)->index();
            $table->string('category', 64)->index();
            $table->string('severity', 32)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('OPEN')->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('acked_at')->nullable();
            $table->foreignId('acked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });

        Schema::create('system_error_records', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('correlation_id', 64)->index();
            $table->string('component', 96)->index();
            $table->string('severity', 32)->index();
            $table->string('code', 96)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('redacted')->default(true);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('validation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 32)->index();
            $table->string('evidence_label', 32)->index();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->json('config')->nullable();
            $table->json('funnel')->nullable();
            $table->json('rejection_analysis')->nullable();
            $table->json('calibration')->nullable();
            $table->boolean('is_backtest')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('validation_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('validation_session_id')->constrained('validation_sessions')->cascadeOnDelete();
            $table->string('stage', 64)->index();
            $table->string('outcome', 64)->index();
            $table->string('symbol', 32)->nullable();
            $table->string('strategy_key', 64)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('research_only')->default(false);
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('performance_drift_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('strategy_key', 64)->index();
            $table->string('evidence_label', 32)->index();
            $table->string('verdict', 32)->index();
            $table->boolean('auto_disable')->default(false);
            $table->boolean('safety_block')->default(false);
            $table->json('metrics')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('data_quality_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('source', 64)->index();
            $table->decimal('score', 8, 4);
            $table->string('verdict', 32)->index();
            $table->boolean('blocks_new_trades')->default(false);
            $table->json('issues')->nullable();
            $table->decimal('clock_drift_ms', 12, 3)->nullable();
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('circuit_breakers', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('name', 64)->unique();
            $table->string('state', 32)->default('CLOSED')->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('threshold')->default(5);
            $table->unsignedInteger('cooldown_seconds')->default(60);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('half_open_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('status', 32)->index();
            $table->string('path')->nullable();
            $table->boolean('verified')->default(false);
            $table->boolean('restore_tested')->default(false);
            $table->boolean('reconcile_required_before_trading')->default(true);
            $table->json('manifest')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dead_letter_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('queue', 64)->index();
            $table->string('job_type', 96)->index();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('disposition', 32)->default('HELD')->index();
            $table->timestamp('failed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('watchdog_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('action', 32)->index();
            $table->string('reason', 255);
            $table->json('context')->nullable();
            $table->boolean('duplicates_orders')->default(false);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ops_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('title');
            $table->string('severity', 32)->index();
            $table->string('status', 32)->default('OPEN')->index();
            $table->text('summary')->nullable();
            $table->json('timeline')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_incidents');
        Schema::dropIfExists('watchdog_events');
        Schema::dropIfExists('dead_letter_jobs');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('circuit_breakers');
        Schema::dropIfExists('data_quality_scores');
        Schema::dropIfExists('performance_drift_checks');
        Schema::dropIfExists('validation_observations');
        Schema::dropIfExists('validation_sessions');
        Schema::dropIfExists('system_error_records');
        Schema::dropIfExists('system_alerts');
        Schema::dropIfExists('system_health_snapshots');
        Schema::dropIfExists('metric_samples');
    }
};
