<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 RiskEngine additive schema.
 * Local/dev only verification path. Never run migrate:fresh against shared/hosted DBs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('is_default');
            $table->string('rules_bundle_version', 32)->default('risk-rules/v1')->after('version');
            $table->string('config_hash', 64)->nullable()->after('rules_bundle_version');
            $table->json('rule_config')->nullable()->after('config_hash');
            $table->json('session_allowlist')->nullable()->after('rule_config');
            $table->decimal('max_correlated_exposure', 8, 4)->default(4)->after('max_open_risk');
            $table->decimal('atr_stop_multiplier', 8, 4)->nullable()->after('min_reward_risk');
            $table->boolean('require_stop_loss')->default(true)->after('atr_stop_multiplier');
            $table->boolean('sizing_enabled')->default(true)->after('require_stop_loss');
        });

        Schema::create('risk_rule_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('version', 32);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->json('default_config')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'priority']);
        });

        Schema::create('proposed_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('risk_decision_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('trading_instrument_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('PROPOSED');
            $table->decimal('proposed_volume', 12, 4);
            $table->decimal('proposed_risk_amount', 18, 4);
            $table->decimal('proposed_risk_percent', 8, 4)->nullable();
            $table->decimal('proposed_entry', 18, 8)->nullable();
            $table->decimal('proposed_stop_loss', 18, 8)->nullable();
            $table->decimal('proposed_take_profit', 18, 8)->nullable();
            $table->decimal('proposed_reward_risk', 12, 4)->nullable();
            $table->decimal('proposed_margin', 18, 4)->nullable();
            $table->json('sizing_breakdown');
            $table->json('symbol_specs');
            $table->string('engine_version', 32);
            $table->string('profile_version', 32)->nullable();
            $table->boolean('broker_routable')->default(false);
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('risk_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('status', 20)->default('ACTIVE');
            $table->decimal('reserved_margin', 18, 4)->default(0);
            $table->decimal('reserved_risk', 18, 4)->default(0);
            $table->decimal('reserved_exposure', 18, 4)->default(0);
            $table->string('symbol', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['broker_account_id', 'status']);
        });

        Schema::create('risk_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lock_type', 40);
            $table->string('reason_code', 50);
            $table->text('message');
            $table->boolean('is_active')->default(true);
            $table->json('context')->nullable();
            $table->timestamp('locked_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'lock_type']);
            $table->index(['broker_account_id', 'is_active']);
        });

        Schema::table('risk_decisions', function (Blueprint $table): void {
            $table->string('engine_version', 32)->nullable()->after('risk_profile_id');
            $table->unsignedInteger('profile_version')->nullable()->after('engine_version');
            $table->string('rules_bundle_version', 32)->nullable()->after('profile_version');
            $table->string('config_hash', 64)->nullable()->after('rules_bundle_version');
            $table->foreignId('proposed_plan_id')->nullable()->after('config_hash');
            $table->boolean('immutable')->default(true)->after('proposed_plan_id');
            $table->json('rule_results')->nullable()->after('checks');
            $table->json('account_context')->nullable()->after('rule_results');
            $table->json('symbol_context')->nullable()->after('account_context');
        });
    }

    public function down(): void
    {
        Schema::table('risk_decisions', function (Blueprint $table): void {
            $table->dropColumn([
                'engine_version', 'profile_version', 'rules_bundle_version', 'config_hash',
                'proposed_plan_id', 'immutable', 'rule_results', 'account_context', 'symbol_context',
            ]);
        });
        Schema::dropIfExists('risk_locks');
        Schema::dropIfExists('risk_reservations');
        Schema::dropIfExists('proposed_plans');
        Schema::dropIfExists('risk_rule_definitions');
        Schema::table('risk_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'version', 'rules_bundle_version', 'config_hash', 'rule_config', 'session_allowlist',
                'max_correlated_exposure', 'atr_stop_multiplier', 'require_stop_loss', 'sizing_enabled',
            ]);
        });
    }
};
