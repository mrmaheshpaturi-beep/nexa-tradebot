<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 DEMO ExecutionEngine additive schema.
 * Local/dev only verification path. Never run migrate:fresh against shared/hosted DBs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->string('environment', 20);
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
            $table->index(['trade_intent_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('execution_submission_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_command_id')->nullable()->constrained()->nullOnDelete();
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

        Schema::create('execution_results', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('execution_command_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('outcome', 32);
            $table->string('retcode', 32)->nullable();
            $table->string('retcode_class', 32)->nullable();
            $table->string('broker_order_id', 64)->nullable();
            $table->string('broker_deal_id', 64)->nullable();
            $table->string('broker_position_id', 64)->nullable();
            $table->decimal('requested_volume', 12, 4)->nullable();
            $table->decimal('filled_volume', 12, 4)->nullable();
            $table->decimal('fill_price', 18, 8)->nullable();
            $table->boolean('partial_fill')->default(false);
            $table->string('filling_mode', 32)->nullable();
            $table->json('request_snapshot');
            $table->json('response_snapshot');
            $table->json('verification_snapshot')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['environment', 'outcome', 'recorded_at']);
        });

        Schema::create('execution_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('execution_command_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->default('DEMO');
            $table->string('event_type', 64);
            $table->string('severity', 16)->default('INFO');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event_type', 'occurred_at']);
            $table->index(['execution_command_id', 'occurred_at']);
        });

        Schema::create('execution_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->default('DEMO');
            $table->string('status', 20)->default('RUNNING');
            $table->unsignedInteger('orders_synced')->default(0);
            $table->unsignedInteger('deals_synced')->default(0);
            $table->unsignedInteger('positions_synced')->default(0);
            $table->unsignedInteger('mismatches')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['environment', 'status', 'started_at']);
        });

        Schema::table('execution_commands', function (Blueprint $table): void {
            $table->string('submission_state', 32)->nullable()->after('status');
            $table->string('bridge_correlation_id', 64)->nullable()->after('submission_state');
            $table->string('bridge_nonce', 64)->nullable()->after('bridge_correlation_id');
            $table->boolean('order_check_passed')->nullable()->after('bridge_nonce');
            $table->timestamp('submitted_at')->nullable()->after('order_check_passed');
            $table->string('unknown_reason', 64)->nullable()->after('submitted_at');
            $table->boolean('blind_retry_forbidden')->default(true)->after('unknown_reason');
        });

        Schema::table('broker_accounts', function (Blueprint $table): void {
            $table->string('broker_login', 64)->nullable()->after('metadata');
            $table->string('broker_server', 128)->nullable()->after('broker_login');
            $table->string('verified_trade_mode', 20)->nullable()->after('broker_server');
            $table->timestamp('demo_verified_at')->nullable()->after('verified_trade_mode');
            $table->json('demo_verification')->nullable()->after('demo_verified_at');
        });

        Schema::table('trade_intents', function (Blueprint $table): void {
            $table->string('confirmation_status', 32)->nullable()->after('status');
            $table->foreignId('active_confirmation_id')->nullable()->after('confirmation_status');
        });
    }

    public function down(): void
    {
        Schema::table('trade_intents', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_status', 'active_confirmation_id']);
        });
        Schema::table('broker_accounts', function (Blueprint $table): void {
            $table->dropColumn(['broker_login', 'broker_server', 'verified_trade_mode', 'demo_verified_at', 'demo_verification']);
        });
        Schema::table('execution_commands', function (Blueprint $table): void {
            $table->dropColumn([
                'submission_state',
                'bridge_correlation_id',
                'bridge_nonce',
                'order_check_passed',
                'submitted_at',
                'unknown_reason',
                'blind_retry_forbidden',
            ]);
        });
        Schema::dropIfExists('execution_reconciliation_runs');
        Schema::dropIfExists('execution_events');
        Schema::dropIfExists('execution_results');
        Schema::dropIfExists('execution_submission_locks');
        Schema::dropIfExists('execution_confirmations');
    }
};
