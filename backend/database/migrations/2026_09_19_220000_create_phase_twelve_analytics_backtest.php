<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Analytics + Backtesting / Research Engine (additive).
 * No broker write path. BACKTEST environment is research-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_datasets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('source_environment', 32); // DEMO | SIMULATION | MIXED_READ — never LIVE broker
            $table->string('status', 32)->default('READY');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('filters')->nullable();
            $table->json('fingerprint'); // deterministic content hash inputs
            $table->string('content_hash', 64);
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'built_at']);
        });

        Schema::create('analytics_dataset_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_dataset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('trade_summary_public_id', 50)->nullable();
            $table->string('symbol', 64)->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('strategy_key', 80)->nullable();
            $table->string('strategy_version', 64)->nullable();
            $table->string('session', 32)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('regime', 48)->nullable();
            $table->decimal('realized_pnl', 18, 4)->nullable();
            $table->decimal('r_multiple', 18, 8)->nullable();
            $table->decimal('mae', 18, 8)->nullable();
            $table->decimal('mfe', 18, 8)->nullable();
            $table->decimal('spread_cost', 18, 8)->nullable();
            $table->decimal('commission', 18, 8)->nullable();
            $table->decimal('slippage', 18, 8)->nullable();
            $table->decimal('swap', 18, 8)->nullable();
            $table->json('payload'); // full lineage: signal, candidate, risk, execution, management
            $table->timestamps();
            $table->unique(['analytics_dataset_id', 'row_index']);
        });

        Schema::create('analytics_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analytics_dataset_id')->constrained()->cascadeOnDelete();
            $table->string('label', 160)->nullable();
            $table->string('content_hash', 64);
            $table->json('metrics'); // core + risk-adjusted + R/MAE/MFE + cost/exec/mgmt
            $table->json('lineage');
            $table->boolean('immutable')->default(true);
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['user_id', 'captured_at']);
        });

        Schema::create('analytics_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analytics_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type', 48); // PERFORMANCE | STRATEGY_EVAL | COMPARISON | TRADE_EXPLORER
            $table->string('title', 200);
            $table->json('body');
            $table->timestamps();
            $table->index(['user_id', 'report_type']);
        });

        Schema::create('backtest_data_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 64);
            $table->string('timeframe', 16);
            $table->string('source', 48)->default('INLINE_OR_MARKET');
            $table->unsignedInteger('candle_count')->default(0);
            $table->string('content_hash', 64);
            $table->json('candles'); // closed OHLC only
            $table->json('mtf_candles')->nullable(); // higher TF closed only
            $table->timestamp('from_open_time')->nullable();
            $table->timestamp('to_close_time')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'symbol', 'timeframe']);
        });

        Schema::create('backtest_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backtest_data_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 32)->default('BACKTEST'); // MUST be BACKTEST
            $table->string('status', 32)->default('QUEUED'); // QUEUED|RUNNING|COMPLETED|FAILED|CANCELLED
            $table->string('run_kind', 48)->default('SINGLE'); // SINGLE|PORTFOLIO|WALK_FORWARD|OPTIMIZATION|MONTE_CARLO
            $table->string('strategy_key', 80);
            $table->string('strategy_version', 64)->nullable();
            $table->unsignedInteger('config_version')->default(1);
            $table->json('parameters');
            $table->json('cost_model');
            $table->string('intrabar_policy', 48)->default('OHLC_PATH');
            $table->boolean('closed_candle_only')->default(true);
            $table->boolean('no_lookahead')->default(true);
            $table->boolean('mtf_protected')->default(true);
            $table->string('lineage_hash', 64)->nullable();
            $table->json('lineage')->nullable();
            $table->json('metrics')->nullable();
            $table->json('equity_curve')->nullable();
            $table->json('trades')->nullable();
            $table->json('warnings')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('seed')->nullable(); // for MC / opt reproducibility
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['environment', 'run_kind']);
        });

        Schema::create('backtest_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backtest_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_type', 48);
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->json('payload')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'queued_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('backtest_walk_forward_folds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fold_index');
            $table->string('phase', 16); // IS | OOS
            $table->unsignedInteger('from_index');
            $table->unsignedInteger('to_index');
            $table->json('metrics')->nullable();
            $table->json('parameters_used')->nullable();
            $table->timestamps();
            $table->unique(['backtest_run_id', 'fold_index', 'phase']);
        });

        Schema::create('backtest_optimization_trials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('trial_index');
            $table->json('parameters');
            $table->json('metrics')->nullable();
            $table->decimal('objective', 18, 8)->nullable();
            $table->boolean('overfit_flag')->default(false);
            $table->json('overfit_notes')->nullable();
            $table->timestamps();
            $table->unique(['backtest_run_id', 'trial_index']);
        });

        Schema::create('backtest_monte_carlo_paths', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('path_index');
            $table->unsignedInteger('seed');
            $table->json('equity_curve')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->unique(['backtest_run_id', 'path_index']);
        });

        Schema::create('research_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backtest_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('analytics_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('backtest_label', 32)->default('BACKTEST');
            $table->string('demo_label', 32)->default('DEMO');
            $table->json('comparison');
            $table->json('warnings')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_comparisons');
        Schema::dropIfExists('backtest_monte_carlo_paths');
        Schema::dropIfExists('backtest_optimization_trials');
        Schema::dropIfExists('backtest_walk_forward_folds');
        Schema::dropIfExists('backtest_jobs');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('backtest_data_snapshots');
        Schema::dropIfExists('analytics_reports');
        Schema::dropIfExists('analytics_snapshots');
        Schema::dropIfExists('analytics_dataset_rows');
        Schema::dropIfExists('analytics_datasets');
    }
};
