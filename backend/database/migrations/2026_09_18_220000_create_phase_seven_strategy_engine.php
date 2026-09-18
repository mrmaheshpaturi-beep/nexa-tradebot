<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — Strategy Engine tables. Non-destructive additive migration.
 * Uses SQLite-compatible types (json columns already used in project).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            if (! Schema::hasColumn('signals', 'fingerprint')) {
                $table->string('fingerprint', 64)->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('signals', 'plugin_key')) {
                $table->string('plugin_key', 64)->nullable();
            }
            if (! Schema::hasColumn('signals', 'configuration_version')) {
                $table->unsignedInteger('configuration_version')->nullable();
            }
            if (! Schema::hasColumn('signals', 'confluence_score')) {
                $table->decimal('confluence_score', 6, 3)->nullable();
            }
            if (! Schema::hasColumn('signals', 'score_breakdown')) {
                $table->json('score_breakdown')->nullable();
            }
            if (! Schema::hasColumn('signals', 'confluence')) {
                $table->json('confluence')->nullable();
            }
            if (! Schema::hasColumn('signals', 'candle_close_key')) {
                $table->string('candle_close_key', 120)->nullable();
            }
            if (! Schema::hasColumn('signals', 'invalidation_reason')) {
                $table->string('invalidation_reason', 120)->nullable();
            }
            if (! Schema::hasColumn('signals', 'auto_simulation')) {
                $table->boolean('auto_simulation')->default(false);
            }
        });

        Schema::table('trading_strategies', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_strategies', 'plugin_key')) {
                $table->string('plugin_key', 64)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('trading_strategies', 'auto_simulation')) {
                $table->boolean('auto_simulation')->default(false);
            }
            if (! Schema::hasColumn('trading_strategies', 'evaluation_mode')) {
                $table->string('evaluation_mode', 30)->default('ON_CANDLE_CLOSE');
            }
            if (! Schema::hasColumn('trading_strategies', 'higher_timeframes')) {
                $table->json('higher_timeframes')->nullable();
            }
        });

        if (! Schema::hasTable('strategy_evaluations')) {
            Schema::create('strategy_evaluations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
                $table->string('plugin_key', 64);
                $table->string('symbol', 20);
                $table->string('timeframe', 10);
                $table->string('candle_close_key', 120);
                $table->unsignedInteger('configuration_version')->default(1);
                $table->string('status', 20);
                $table->string('direction', 10)->nullable();
                $table->decimal('raw_score', 6, 3)->default(0);
                $table->decimal('confluence_score', 6, 3)->nullable();
                $table->json('score_breakdown')->nullable();
                $table->json('evidence')->nullable();
                $table->json('confluence')->nullable();
                $table->text('reason')->nullable();
                $table->json('gate')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['trading_strategy_id', 'symbol', 'timeframe', 'candle_close_key', 'plugin_key'], 'strategy_eval_unique');
                $table->index(['symbol', 'timeframe', 'status']);
            });
        }

        if (! Schema::hasTable('strategy_performance_stats')) {
            Schema::create('strategy_performance_stats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
                $table->string('symbol', 20)->nullable();
                $table->string('timeframe', 10)->nullable();
                $table->unsignedInteger('signals_generated')->default(0);
                $table->unsignedInteger('signals_expired')->default(0);
                $table->unsignedInteger('signals_consumed')->default(0);
                $table->unsignedInteger('evaluations')->default(0);
                $table->decimal('avg_score', 6, 3)->nullable();
                $table->json('by_direction')->nullable();
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();
                $table->unique(['trading_strategy_id', 'symbol', 'timeframe'], 'strategy_perf_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_performance_stats');
        Schema::dropIfExists('strategy_evaluations');
        // Additive column drops omitted for SQLite safety in shared environments.
    }
};
