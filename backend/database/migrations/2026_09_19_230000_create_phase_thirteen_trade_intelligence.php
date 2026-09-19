<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — Trade Intelligence Engine (additive).
 * Advisory/shadow only. Zero broker writes. No risk/settings/strategy mutation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 64);
            $table->string('timeframe', 16)->nullable();
            $table->string('mode', 32)->default('ADVISORY'); // ADVISORY | SHADOW
            $table->string('status', 32)->default('READY');
            $table->string('engine_version', 64)->default('TradeIntelligence/v1');
            $table->string('content_hash', 64);
            $table->string('input_hash', 64);
            $table->json('technical')->nullable();
            $table->json('mtf')->nullable();
            $table->json('regime')->nullable();
            $table->json('ensemble')->nullable();
            $table->json('market_quality')->nullable();
            $table->json('volatility')->nullable();
            $table->json('spread')->nullable();
            $table->json('anomaly')->nullable();
            $table->json('calendar')->nullable();
            $table->json('news')->nullable();
            $table->json('opportunity')->nullable();
            $table->json('evidence_buckets')->nullable(); // STRICTLY separated labels
            $table->json('rules_fired')->nullable();
            $table->json('payload'); // full assessment
            $table->decimal('confidence', 8, 4)->nullable();
            $table->string('confidence_status', 48)->nullable();
            $table->boolean('live_execution')->default(false); // always false
            $table->boolean('order_send')->default(false); // always false
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['user_id', 'symbol', 'assessed_at']);
            $table->index(['mode', 'status']);
        });

        Schema::create('intelligence_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 64);
            $table->string('direction', 16)->nullable();
            $table->string('mode', 32)->default('ADVISORY');
            $table->decimal('rank_score', 10, 4)->default(0);
            $table->unsignedInteger('rank_position')->nullable();
            $table->json('ranking_breakdown')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('evidence_families')->nullable();
            $table->json('payload');
            $table->string('disclaimer', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'rank_score']);
            $table->index(['symbol', 'mode']);
        });

        Schema::create('intelligence_ai_analyses', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64); // MOCK | OPENAI | ANTHROPIC | UNAVAILABLE
            $table->string('model_version', 80);
            $table->string('prompt_version', 80);
            $table->string('input_hash', 64);
            $table->string('output_hash', 64)->nullable();
            $table->string('status', 32)->default('COMPLETED'); // COMPLETED|FAILED|UNAVAILABLE|BUDGET_EXCEEDED
            $table->json('structured_output')->nullable();
            $table->json('raw_meta')->nullable();
            $table->json('validation_errors')->nullable();
            $table->boolean('injection_blocked')->default(false);
            $table->boolean('mutation_tools_available')->default(false); // always false
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'provider', 'analyzed_at']);
        });

        Schema::create('intelligence_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_ai_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16); // user | assistant | system
            $table->text('content');
            $table->boolean('read_only')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('intelligence_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64);
            $table->string('provider_status', 32)->default('OK'); // OK|UNAVAILABLE|DEGRADED
            $table->string('currency', 16)->nullable();
            $table->string('title', 255);
            $table->string('impact', 32)->nullable();
            $table->timestamp('event_at')->nullable();
            $table->boolean('is_fabricated')->default(false); // mock events must be labeled
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['provider_status', 'event_at']);
        });

        Schema::create('intelligence_news_items', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64);
            $table->string('provider_status', 32)->default('OK');
            $table->string('headline', 500);
            $table->string('source_label', 160)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_fabricated')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['provider_status', 'published_at']);
        });

        Schema::create('intelligence_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('job_type', 48);
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'queued_at']);
        });

        Schema::create('intelligence_usage_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meter_key', 80); // ai_calls|news_calls|calendar_calls|assessments
            $table->unsignedInteger('period_yyyymm');
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('budget')->default(1000);
            $table->timestamps();
            $table->unique(['user_id', 'meter_key', 'period_yyyymm']);
        });

        Schema::create('intelligence_calibration_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('evidence_label', 48); // HISTORICAL|DEMO|BACKTEST|OOS|EXECUTION|PORTFOLIO
            $table->decimal('predicted_confidence', 8, 4);
            $table->boolean('outcome_positive')->nullable();
            $table->unsignedInteger('sample_size_context')->default(0);
            $table->string('guard_status', 48)->nullable(); // OK|INSUFFICIENT_SAMPLES|MIXED_LABEL_REFUSED
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'evidence_label']);
        });

        Schema::create('intelligence_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 32)->default('GLOBAL'); // GLOBAL|USER
            $table->string('ai_provider', 64)->default('MOCK');
            $table->string('news_provider', 64)->default('MOCK');
            $table->string('calendar_provider', 64)->default('MOCK');
            $table->string('mode', 32)->default('ADVISORY'); // ADVISORY|SHADOW
            $table->unsignedInteger('ai_budget_monthly')->default(500);
            $table->unsignedInteger('cache_ttl_seconds')->default(120);
            $table->boolean('paid_providers_enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_settings');
        Schema::dropIfExists('intelligence_calibration_samples');
        Schema::dropIfExists('intelligence_usage_meters');
        Schema::dropIfExists('intelligence_jobs');
        Schema::dropIfExists('intelligence_news_items');
        Schema::dropIfExists('intelligence_calendar_events');
        Schema::dropIfExists('intelligence_chat_messages');
        Schema::dropIfExists('intelligence_ai_analyses');
        Schema::dropIfExists('intelligence_opportunities');
        Schema::dropIfExists('intelligence_assessments');
    }
};
