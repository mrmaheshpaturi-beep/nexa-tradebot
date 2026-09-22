<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Trade Management Engine additive schema.
 * Local/dev only verification path. Never run migrate:fresh against shared/hosted DBs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_management_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('break_even_enabled')->default(true);
            $table->string('break_even_trigger_type', 32)->default('R_MULTIPLE');
            $table->decimal('break_even_trigger_value', 18, 8)->default(1);
            $table->decimal('break_even_offset', 18, 8)->default(0);
            $table->boolean('trailing_enabled')->default(false);
            $table->string('trailing_type', 32)->default('FIXED_DISTANCE');
            $table->decimal('trailing_start', 18, 8)->nullable();
            $table->decimal('trailing_distance', 18, 8)->nullable();
            $table->decimal('trailing_step', 18, 8)->nullable();
            $table->decimal('trailing_atr_multiplier', 18, 8)->nullable();
            $table->boolean('partial_close_enabled')->default(false);
            $table->json('partial_close_levels')->nullable();
            $table->boolean('take_profit_management')->default(true);
            $table->boolean('time_exit_enabled')->default(false);
            $table->unsignedInteger('maximum_trade_duration_minutes')->nullable();
            $table->boolean('strategy_invalidation_exit')->default(false);
            $table->boolean('session_exit')->default(false);
            $table->boolean('weekend_exit')->default(false);
            $table->boolean('risk_exit')->default(true);
            $table->boolean('emergency_exit')->default(true);
            $table->string('manual_change_disposition', 32)->default('REQUIRES_REVIEW');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['name', 'version']);
            $table->index(['is_active', 'name']);
        });

        Schema::create('managed_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('execution_command_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_candidate_id')->nullable()->constrained('signal_candidates')->nullOnDelete();
            $table->foreignId('management_policy_id')->nullable()->constrained('trade_management_policies')->nullOnDelete();
            $table->unsignedInteger('management_policy_version')->nullable();
            $table->string('ownership', 32)->default('NEXA_MANAGED');
            $table->string('management_status', 32)->default('DETECTED');
            $table->string('broker_position_id', 64)->nullable();
            $table->string('symbol', 64);
            $table->string('broker_symbol', 64)->nullable();
            $table->string('direction', 16);
            $table->string('environment', 20)->default('DEMO');
            $table->decimal('initial_volume', 12, 4);
            $table->decimal('current_volume', 12, 4);
            $table->decimal('entry_price', 18, 8);
            $table->decimal('initial_stop_loss', 18, 8)->nullable();
            $table->decimal('current_stop_loss', 18, 8)->nullable();
            $table->decimal('initial_take_profit', 18, 8)->nullable();
            $table->decimal('current_take_profit', 18, 8)->nullable();
            $table->decimal('realized_profit', 18, 4)->default(0);
            $table->decimal('floating_profit', 18, 4)->default(0);
            $table->decimal('r_multiple', 18, 8)->nullable();
            $table->decimal('mae', 18, 8)->nullable();
            $table->decimal('mfe', 18, 8)->nullable();
            $table->boolean('break_even_applied')->default(false);
            $table->timestamp('break_even_applied_at')->nullable();
            $table->boolean('trailing_active')->default(false);
            $table->decimal('trailing_extreme', 18, 8)->nullable();
            $table->decimal('last_trail_stop', 18, 8)->nullable();
            $table->string('close_reason', 48)->nullable();
            $table->boolean('auto_management_paused')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_managed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('targets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['broker_account_id', 'broker_position_id']);
            $table->index(['user_id', 'management_status']);
            $table->index(['ownership', 'management_status']);
            $table->index(['environment', 'management_status']);
        });

        Schema::create('managed_position_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->string('label', 16);
            $table->unsignedTinyInteger('sequence');
            $table->decimal('price', 18, 8)->nullable();
            $table->decimal('close_percent', 8, 4);
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('hit_at')->nullable();
            $table->decimal('closed_volume', 12, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['managed_position_id', 'sequence']);
            $table->unique(['managed_position_id', 'label']);
        });

        Schema::create('trade_management_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision_type', 32);
            $table->string('status', 32)->default('PROPOSED');
            $table->string('rule_code', 64)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('why', 512)->nullable();
            $table->decimal('proposed_sl', 18, 8)->nullable();
            $table->decimal('proposed_tp', 18, 8)->nullable();
            $table->decimal('proposed_close_volume', 12, 4)->nullable();
            $table->json('market_snapshot')->nullable();
            $table->json('risk_snapshot')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['managed_position_id', 'status', 'decided_at'], 'tm_decision_position_status_at_idx');
            $table->index(['decision_type', 'status']);
        });

        Schema::create('position_management_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('decision_id')->nullable()->constrained('trade_management_decisions')->nullOnDelete();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->string('action_type', 32);
            $table->string('status', 32)->default('CREATED');
            $table->string('idempotency_key', 160);
            $table->decimal('requested_sl', 18, 8)->nullable();
            $table->decimal('requested_tp', 18, 8)->nullable();
            $table->decimal('requested_close_volume', 12, 4)->nullable();
            $table->string('broker_request_reference', 64)->nullable();
            $table->string('bridge_correlation_id', 64)->nullable();
            $table->string('bridge_nonce', 64)->nullable();
            $table->boolean('order_check_passed')->nullable();
            $table->boolean('blind_retry_forbidden')->default(true);
            $table->string('unknown_reason', 64)->nullable();
            $table->string('retcode', 32)->nullable();
            $table->json('request_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->json('verification_snapshot')->nullable();
            $table->timestamp('created_at_action')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['managed_position_id', 'status']);
            $table->index(['action_type', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('position_management_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_management_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lock_key', 160);
            $table->string('status', 20)->default('HELD');
            $table->string('owner_token', 64);
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['lock_key']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('broker_action_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 40);
            $table->boolean('is_active')->default(true);
            $table->string('reason', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'scope']);
        });

        Schema::create('trade_management_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decision_id')->nullable()->constrained('trade_management_decisions')->nullOnDelete();
            $table->foreignId('action_id')->nullable()->constrained('position_management_actions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->default('DEMO');
            $table->string('event_type', 64);
            $table->string('severity', 16)->default('INFO');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event_type', 'occurred_at']);
            $table->index(['managed_position_id', 'occurred_at']);
        });

        Schema::create('managed_position_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->default('SYNC');
            $table->json('snapshot');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['managed_position_id', 'captured_at']);
        });

        Schema::create('trade_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('managed_position_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 64);
            $table->string('direction', 16);
            $table->decimal('entry_price', 18, 8);
            $table->decimal('exit_price', 18, 8)->nullable();
            $table->decimal('initial_volume', 12, 4);
            $table->decimal('closed_volume', 12, 4)->nullable();
            $table->decimal('realized_pnl', 18, 4)->nullable();
            $table->decimal('mae', 18, 8)->nullable();
            $table->decimal('mfe', 18, 8)->nullable();
            $table->decimal('r_multiple', 18, 8)->nullable();
            $table->string('close_reason', 48)->nullable();
            $table->boolean('break_even_applied')->default(false);
            $table->boolean('trailing_used')->default(false);
            $table->unsignedTinyInteger('partials_count')->default(0);
            $table->json('timeline')->nullable();
            $table->boolean('finalized')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'finalized_at']);
        });

        Schema::create('management_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('managed_position_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 32);
            $table->string('status', 32)->default('INITIATED');
            $table->unsignedTinyInteger('step')->default(1);
            $table->string('challenge_token_hash', 128)->nullable();
            $table->string('confirm_token_hash', 128)->nullable();
            $table->string('idempotency_key', 120);
            $table->json('preview_payload')->nullable();
            $table->json('fresh_context')->nullable();
            $table->timestamp('step1_at')->nullable();
            $table->timestamp('step2_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['managed_position_id', 'status']);
        });

        Schema::table('risk_locks', function (Blueprint $table): void {
            if (! Schema::hasColumn('risk_locks', 'blocks_new_entries')) {
                $table->boolean('blocks_new_entries')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('risk_locks', 'blocks_protective_closes')) {
                $table->boolean('blocks_protective_closes')->default(false)->after('blocks_new_entries');
            }
        });
    }

    public function down(): void
    {
        Schema::table('risk_locks', function (Blueprint $table): void {
            if (Schema::hasColumn('risk_locks', 'blocks_protective_closes')) {
                $table->dropColumn('blocks_protective_closes');
            }
            if (Schema::hasColumn('risk_locks', 'blocks_new_entries')) {
                $table->dropColumn('blocks_new_entries');
            }
        });
        Schema::dropIfExists('management_confirmations');
        Schema::dropIfExists('trade_summaries');
        Schema::dropIfExists('managed_position_snapshots');
        Schema::dropIfExists('trade_management_events');
        Schema::dropIfExists('broker_action_locks');
        Schema::dropIfExists('position_management_locks');
        Schema::dropIfExists('position_management_actions');
        Schema::dropIfExists('trade_management_decisions');
        Schema::dropIfExists('managed_position_targets');
        Schema::dropIfExists('managed_positions');
        Schema::dropIfExists('trade_management_policies');
    }
};
