<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Market Scanner + Signal Orchestrator tables.
 * Additive, SQLite-compatible. No broker execution fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scanner_configs')) {
            Schema::create('scanner_configs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120)->default('Default Universe');
                $table->json('symbols');
                $table->json('timeframes');
                $table->json('plugin_keys')->nullable();
                $table->json('strategy_ids')->nullable();
                $table->string('trigger_mode', 30)->default('MANUAL');
                $table->unsignedInteger('interval_seconds')->nullable();
                $table->boolean('enabled')->default(true);
                $table->string('prefer', 20)->default('simulation');
                $table->boolean('create_signals')->default(true);
                $table->boolean('create_candidates')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'enabled']);
            });
        }

        if (! Schema::hasTable('scanner_runs')) {
            Schema::create('scanner_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('scanner_config_id')->nullable()->constrained('scanner_configs')->nullOnDelete();
                $table->string('run_key', 64);
                $table->string('trigger', 30);
                $table->string('status', 20)->default('RUNNING');
                $table->string('prefer', 20)->default('simulation');
                $table->unsignedInteger('symbols_scanned')->default(0);
                $table->unsignedInteger('timeframes_scanned')->default(0);
                $table->unsignedInteger('strategies_evaluated')->default(0);
                $table->unsignedInteger('candidates_created')->default(0);
                $table->unsignedInteger('signals_created')->default(0);
                $table->unsignedInteger('errors_count')->default(0);
                $table->json('errors')->nullable();
                $table->json('summary')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'run_key'], 'scanner_runs_user_run_key_unique');
                $table->index(['user_id', 'status', 'finished_at']);
            });
        }

        if (! Schema::hasTable('signal_candidates')) {
            Schema::create('signal_candidates', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 40)->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('scanner_run_id')->nullable()->constrained('scanner_runs')->nullOnDelete();
                $table->foreignId('signal_id')->nullable()->constrained('signals')->nullOnDelete();
                $table->foreignId('trading_strategy_id')->nullable()->constrained('trading_strategies')->nullOnDelete();
                $table->string('plugin_key', 64)->nullable();
                $table->string('symbol', 20);
                $table->string('timeframe', 10);
                $table->string('direction', 10);
                $table->string('status', 20)->default('QUEUED');
                $table->decimal('rank_score', 8, 3)->default(0);
                $table->unsignedInteger('priority')->default(100);
                $table->decimal('confluence_score', 6, 3)->nullable();
                $table->string('candle_close_key', 120)->nullable();
                $table->string('fingerprint', 64);
                $table->string('conflict_group', 80)->nullable();
                $table->json('conflict_flags')->nullable();
                $table->json('score_breakdown')->nullable();
                $table->json('confluence')->nullable();
                $table->json('evidence')->nullable();
                $table->json('quality')->nullable();
                $table->json('freshness')->nullable();
                $table->boolean('marked_for_simulate')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->string('invalidation_reason', 120)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'fingerprint'], 'signal_candidates_user_fp_unique');
                $table->index(['user_id', 'status', 'rank_score']);
                $table->index(['symbol', 'timeframe', 'direction']);
            });
        }

        if (! Schema::hasTable('scanner_alert_events')) {
            Schema::create('scanner_alert_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('signal_candidate_id')->nullable()->constrained('signal_candidates')->nullOnDelete();
                $table->foreignId('scanner_run_id')->nullable()->constrained('scanner_runs')->nullOnDelete();
                $table->string('event_type', 60);
                $table->string('severity', 20)->default('INFO');
                $table->string('channel', 30)->default('IN_APP');
                $table->string('title');
                $table->text('body')->nullable();
                $table->json('payload')->nullable();
                $table->boolean('delivered')->default(false);
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'event_type', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scanner_alert_events');
        Schema::dropIfExists('signal_candidates');
        Schema::dropIfExists('scanner_runs');
        Schema::dropIfExists('scanner_configs');
    }
};
