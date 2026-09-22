<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — Automated DEMO Trading Orchestrator (additive).
 * Modes: OFF | DRY_RUN | DEMO_AUTO. LIVE_AUTO does not exist.
 * Phase 14 never calls order_send; Phase 10 remains sole execution path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('DRAFT'); // DRAFT|VALIDATED|ACTIVE|ARCHIVED
            $table->string('config_hash', 64);
            $table->json('symbol_universe');
            $table->json('timeframe_universe');
            $table->json('strategy_matrix'); // symbol×tf×strategy_version locks
            $table->json('qualification_rules')->nullable();
            $table->json('risk_overrides')->nullable(); // never mutates RiskEngine code; profile caps only
            $table->json('reentry_policy')->nullable(); // conservative defaults
            $table->json('session_policy')->nullable();
            $table->json('calendar_policy')->nullable();
            $table->boolean('intelligence_required')->default(true);
            $table->boolean('closed_candle_only')->default(true);
            $table->unsignedInteger('max_open_positions')->default(3);
            $table->unsignedInteger('max_trades_per_day')->default(10);
            $table->unsignedInteger('max_trades_per_symbol_per_day')->default(3);
            $table->unsignedInteger('loss_streak_lock')->default(3);
            $table->unsignedInteger('cooldown_seconds')->default(300);
            $table->unsignedInteger('signal_ttl_seconds')->default(600);
            $table->boolean('allow_revenge_trading')->default(false); // always enforced false
            $table->boolean('allow_martingale')->default(false); // always enforced false
            $table->boolean('online_self_optimization')->default(false); // always false
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('automation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 32)->default('OFF'); // OFF|DRY_RUN|DEMO_AUTO — NO LIVE_AUTO
            $table->string('state', 32)->default('OFF'); // OFF|STARTING|RUNNING|PAUSED|DEGRADED|SAFE_MODE|STOPPING|ERROR
            $table->string('config_snapshot_hash', 64);
            $table->json('config_snapshot');
            $table->boolean('auto_entry_paused')->default(true); // restart default
            $table->boolean('entries_blocked')->default(false);
            $table->boolean('safe_mode')->default(false);
            $table->boolean('kill_switch')->default(false);
            $table->string('account_trade_mode', 32)->nullable(); // must be DEMO for DEMO_AUTO
            $table->string('last_account_check_at')->nullable();
            $table->string('pause_reason', 255)->nullable();
            $table->string('safe_mode_reason', 255)->nullable();
            $table->string('error_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            $table->unsignedInteger('tick_count')->default(0);
            $table->json('preflight')->nullable();
            $table->json('counters')->nullable(); // open/day/symbol/streak snapshots
            $table->timestamps();
            $table->index(['user_id', 'state']);
            $table->index(['mode', 'state']);
        });

        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_profile_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 128); // duplicate protection
            $table->string('state', 48)->default('CANDIDATE_RECEIVED');
            $table->string('symbol', 64);
            $table->string('timeframe', 16)->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('strategy_key', 80)->nullable();
            $table->string('strategy_version', 64)->nullable();
            $table->foreignId('signal_candidate_id')->nullable();
            $table->foreignId('intelligence_assessment_id')->nullable();
            $table->foreignId('trade_intent_id')->nullable()->constrained('trade_intents')->nullOnDelete();
            $table->foreignId('risk_decision_id')->nullable();
            $table->foreignId('execution_command_id')->nullable();
            $table->foreignId('managed_position_id')->nullable();
            $table->foreignId('position_id')->nullable();
            $table->string('rejection_code', 80)->nullable();
            $table->string('rejection_stage', 64)->nullable();
            $table->text('rejection_detail')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->boolean('broker_touched')->default(false); // true only after Phase 10 submit
            $table->json('trace')->nullable(); // full stage timeline
            $table->json('qualification')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('signal_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['automation_session_id', 'fingerprint']);
            $table->index(['user_id', 'state']);
            $table->index(['symbol', 'state']);
        });

        Schema::create('automation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('severity', 16)->default('INFO'); // INFO|WARN|ERROR|CRITICAL|AUDIT
            $table->boolean('immutable')->default(true);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['automation_session_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('automation_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lock_type', 48); // ENTRY|SYMBOL|SESSION|KILL|SAFE_MODE|LOSS_STREAK|DAILY_LOSS|DRAWDOWN|SCHEDULER
            $table->string('scope_key', 128)->nullable();
            $table->unsignedInteger('precedence')->default(100);
            $table->string('reason', 255);
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable(); // TTL for distributed locks
            $table->timestamp('released_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'lock_type', 'active']);
            $table->index(['scope_key', 'active']);
        });

        Schema::create('automation_daily_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('day_key', 16); // YYYY-MM-DD UTC
            $table->string('symbol', 64)->nullable();
            $table->unsignedInteger('trades')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('consecutive_losses')->default(0);
            $table->decimal('realized_pnl', 18, 4)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'day_key', 'symbol'], 'automation_daily_unique');
        });

        Schema::create('automation_queue_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('queue_name', 48); // SAFETY|EXECUTION|SCAN|INTELLIGENCE|ANALYTICS
            $table->unsignedInteger('priority')->default(50); // lower = higher priority; SAFETY=0
            $table->string('job_type', 80);
            $table->string('status', 32)->default('PENDING'); // PENDING|RUNNING|DONE|FAILED|EXPIRED
            $table->json('payload')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['queue_name', 'status', 'priority', 'available_at'], 'auto_queue_status_priority_at_idx');
        });

        Schema::create('automation_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32)->default('IN_APP'); // IN_APP only required; email/telegram foundation
            $table->string('severity', 16)->default('INFO');
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->boolean('read')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_notifications');
        Schema::dropIfExists('automation_queue_jobs');
        Schema::dropIfExists('automation_daily_counters');
        Schema::dropIfExists('automation_locks');
        Schema::dropIfExists('automation_events');
        Schema::dropIfExists('automation_workflows');
        Schema::dropIfExists('automation_sessions');
        Schema::dropIfExists('automation_profiles');
    }
};
