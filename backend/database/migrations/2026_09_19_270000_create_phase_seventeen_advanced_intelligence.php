<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 — Advanced Market Intelligence (additive).
 * Extends Phase 13; advisory/shadow only. Zero broker/risk/approval/deployment writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_advanced_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Keep the constraint name within MySQL's 64-character identifier limit.
            $table->foreignId('intelligence_assessment_id')->nullable();
            $table->foreign('intelligence_assessment_id', 'intel_adv_snapshot_assessment_fk')
                ->references('id')->on('intelligence_assessments')->nullOnDelete();
            $table->string('symbol', 64);
            $table->string('timeframe', 16)->nullable();
            $table->string('mode', 32)->default('ADVISORY');
            $table->string('status', 32)->default('READY');
            $table->string('orchestrator_version', 64)->default('AdvancedIntelligence/v1');
            $table->string('feature_schema_version', 64)->default('market-features/v1');
            $table->string('content_hash', 64);
            $table->json('features')->nullable();
            $table->json('deep_structure')->nullable();
            $table->json('mtf_matrix')->nullable();
            $table->json('ensemble')->nullable();
            $table->json('cross_market')->nullable();
            $table->json('analogs')->nullable();
            $table->json('context_pack')->nullable();
            $table->json('uncertainty')->nullable();
            $table->json('suitability')->nullable();
            $table->json('scoring_separation')->nullable();
            $table->json('payload');
            $table->decimal('confidence', 8, 4)->nullable();
            $table->boolean('live_execution')->default(false);
            $table->boolean('order_send')->default(false);
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['user_id', 'symbol', 'assessed_at'], 'intel_adv_user_sym_assessed');
            $table->index(['mode', 'status'], 'intel_adv_mode_status');
        });

        Schema::create('intelligence_memory_records', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32); // PRE_TRADE | POST_TRADE | RESEARCH | SHADOW_NOTE
            $table->string('symbol', 64)->nullable();
            $table->string('mode', 32)->default('ADVISORY');
            $table->string('content_hash', 64);
            $table->json('payload');
            $table->boolean('immutable')->default(true);
            $table->string('orchestrator_version', 64)->default('AdvancedIntelligence/v1');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['user_id', 'kind', 'recorded_at'], 'intel_mem_user_kind_rec');
            $table->index(['symbol', 'kind'], 'intel_mem_sym_kind');
        });

        Schema::create('intelligence_feature_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->string('schema_version', 64);
            $table->string('feature_hash', 64);
            $table->string('symbol', 64)->nullable();
            $table->json('features');
            $table->boolean('fresh')->default(true);
            $table->unsignedInteger('age_seconds')->default(0);
            $table->boolean('lookahead_safe')->default(true);
            $table->timestamp('extracted_at');
            $table->timestamps();
            $table->index(['schema_version', 'feature_hash'], 'intel_feat_schema_hash');
        });

        Schema::table('intelligence_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('intelligence_settings', 'advanced_enabled')) {
                $table->boolean('advanced_enabled')->default(true)->after('mode');
            }
            if (! Schema::hasColumn('intelligence_settings', 'orchestrator_version')) {
                $table->string('orchestrator_version', 64)->default('AdvancedIntelligence/v1')->after('advanced_enabled');
            }
            if (! Schema::hasColumn('intelligence_settings', 'ai_timeout_ms')) {
                $table->unsignedInteger('ai_timeout_ms')->default(5000)->after('cache_ttl_seconds');
            }
            if (! Schema::hasColumn('intelligence_settings', 'cost_budget_tokens')) {
                $table->unsignedInteger('cost_budget_tokens')->default(100000)->after('ai_budget_monthly');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intelligence_settings', function (Blueprint $table): void {
            foreach (['orchestrator_version', 'advanced_enabled', 'ai_timeout_ms', 'cost_budget_tokens'] as $col) {
                if (Schema::hasColumn('intelligence_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('intelligence_feature_versions');
        Schema::dropIfExists('intelligence_memory_records');
        Schema::dropIfExists('intelligence_advanced_snapshots');
    }
};
