<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 — Multi-Broker / Multi-Account Fleet Architecture.
 * Additive only. Does not create order_send paths or LIVE_AUTO.
 * Execution remains Phase 10 sole authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('platform', 32)->default('MT5'); // MT5|SIMULATION|OTHER
            $table->string('status', 20)->default('ACTIVE');
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'code']);
        });

        Schema::create('broker_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mt5_bridge_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('endpoint_mode', 32)->default('MOCK'); // MOCK|HTTP_BRIDGE|WINDOWS_TERMINAL
            $table->string('status', 32)->default('DISCONNECTED');
            $table->string('health', 32)->default('UNKNOWN');
            $table->timestamp('last_health_at')->nullable();
            $table->json('health_payload')->nullable();
            $table->json('secret_ref')->nullable(); // never store raw credentials; opaque refs only
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('fleet_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('login', 64)->nullable();
            $table->string('server', 128)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->string('environment', 20)->default('DEMO'); // DEMO|SIMULATION — LIVE stored but hard-blocked
            $table->string('verified_trade_mode', 20)->nullable();
            $table->timestamp('environment_verified_at')->nullable();
            $table->string('isolation_mode', 32)->default('STRICT'); // STRICT|SAFE_MODE
            $table->string('status', 32)->default('REGISTERED');
            $table->boolean('trading_enabled')->default(false);
            $table->boolean('safe_mode')->default(false);
            $table->string('safe_mode_reason', 160)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'broker_account_id']);
            $table->index(['user_id', 'environment', 'status']);
        });

        Schema::create('account_fingerprints', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint_hash', 64);
            $table->string('login', 64);
            $table->string('server', 128);
            $table->string('company', 160)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('trade_mode', 20);
            $table->unsignedInteger('leverage')->nullable();
            $table->boolean('is_current')->default(true);
            $table->json('raw_snapshot')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['fleet_account_id', 'is_current']);
            $table->unique(['fleet_account_id', 'fingerprint_hash']);
        });

        Schema::create('broker_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('capability_key', 64);
            $table->boolean('supported')->default(false);
            $table->boolean('enabled')->default(false);
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['broker_provider_id', 'fleet_account_id', 'capability_key'], 'broker_cap_unique');
        });

        Schema::create('fleet_terminals', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_label', 80);
            $table->string('isolation_key', 120);
            $table->string('status', 32)->default('REGISTERED');
            $table->string('supervisor_state', 32)->default('IDLE');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('bound_login_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'isolation_key']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('canonical_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->string('symbol', 32);
            $table->string('asset_class', 32)->default('FX');
            $table->string('base_currency', 8)->nullable();
            $table->string('quote_currency', 8)->nullable();
            $table->unsignedTinyInteger('digits')->default(5);
            $table->decimal('pip_size', 18, 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['symbol']);
        });

        Schema::create('broker_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('canonical_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('broker_symbol', 64);
            $table->string('status', 20)->default('ACTIVE');
            $table->json('spec')->nullable();
            $table->timestamp('spec_fetched_at')->nullable();
            $table->timestamp('spec_fresh_until')->nullable();
            $table->boolean('spec_stale')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['broker_provider_id', 'broker_symbol', 'fleet_account_id'], 'broker_instr_unique');
        });

        Schema::create('instrument_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_instrument_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_status', 20)->default('ACTIVE');
            $table->decimal('contract_multiplier', 18, 8)->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['canonical_instrument_id', 'broker_instrument_id'], 'instr_map_unique');
        });

        Schema::create('trading_portfolios', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('base_currency', 8)->default('USD');
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('ai_mutable')->default(false); // always false in product logic
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('portfolio_memberships', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('MEMBER'); // PRIMARY|MEMBER|OBSERVER
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['trading_portfolio_id', 'fleet_account_id'], 'portfolio_member_unique');
        });

        Schema::create('allocation_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_portfolio_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('DRAFT'); // DRAFT|ACTIVE|SUPERSEDED
            $table->string('plan_hash', 64);
            $table->json('weights'); // deterministic account public_id => weight
            $table->boolean('ai_authored')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['trading_portfolio_id', 'version']);
            $table->index(['trading_portfolio_id', 'status']);
        });

        Schema::create('strategy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_portfolio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governed_strategy_version_id')->nullable()->constrained('governed_strategy_versions')->nullOnDelete();
            $table->string('strategy_key', 80);
            $table->string('lifecycle_gate', 32); // must be APPROVED or DEPLOYED_DEMO
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('ai_assigned')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['fleet_account_id', 'status']);
            $table->unique(['fleet_account_id', 'strategy_key', 'status'], 'strategy_assign_unique');
        });

        Schema::create('fleet_risk_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32); // ACCOUNT|PORTFOLIO|GLOBAL
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_portfolio_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lock_code', 64);
            $table->string('reason', 255);
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('ai_created')->default(false);
            $table->timestamp('activated_at');
            $table->timestamp('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'scope', 'status']);
        });

        Schema::create('execution_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('route_decision', 32); // ROUTED_PHASE10|BLOCKED|SAFE_MODE|REFUSED
            $table->string('block_reason', 160)->nullable();
            $table->boolean('copy_trading')->default(false); // always false
            $table->boolean('ai_routed')->default(false); // always false when accepted
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'fleet_account_id', 'idempotency_key'], 'exec_route_idem_unique');
            $table->index(['fleet_account_id', 'route_decision']);
        });

        Schema::create('fleet_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('RUNNING');
            $table->unsignedInteger('foreign_positions')->default(0);
            $table->unsignedInteger('matched_positions')->default(0);
            $table->unsignedInteger('mismatches')->default(0);
            $table->boolean('safe_mode_triggered')->default(false);
            $table->boolean('restart_recovery')->default(false);
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['fleet_account_id', 'started_at']);
        });

        Schema::create('fleet_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32)->default('FLEET'); // FLEET|ACCOUNT|CONNECTION|NODE
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('overall', 32);
            $table->json('components');
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['user_id', 'scope', 'observed_at']);
        });

        Schema::create('fleet_emergency_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32); // ACCOUNT|FLEET
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48); // HALT|SAFE_MODE|KILL_AUTOMATION|RESUME
            $table->string('status', 20)->default('ACTIVE');
            $table->string('reason', 255);
            $table->boolean('ai_initiated')->default(false);
            $table->timestamp('activated_at');
            $table->timestamp('cleared_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'scope', 'status']);
        });

        Schema::create('automation_account_scopes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_profile_id')->nullable();
            $table->string('mode', 32)->default('OFF'); // OFF|DRY_RUN|DEMO_AUTO — never LIVE_AUTO
            $table->boolean('enabled')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'fleet_account_id']);
        });

        Schema::create('fx_valuation_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->string('base_currency', 8);
            $table->string('quote_currency', 8);
            $table->decimal('rate', 18, 8);
            $table->string('source', 32)->default('MOCK');
            $table->timestamp('as_of');
            $table->timestamps();
            $table->unique(['base_currency', 'quote_currency', 'as_of']);
        });

        Schema::create('trading_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 80);
            $table->string('hostname', 160)->nullable();
            $table->string('status', 32)->default('REGISTERED');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'node_id']);
        });

        Schema::create('trading_node_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->constrained()->cascadeOnDelete();
            $table->string('lease_token', 64);
            $table->string('status', 20)->default('HELD'); // HELD|EXPIRED|RELEASED|SPLIT_BRAIN_BLOCKED
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->boolean('split_brain_detected')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['fleet_account_id', 'status', 'lease_token'], 'node_lease_unique');
            $table->index(['fleet_account_id', 'status', 'expires_at']);
        });

        Schema::create('fleet_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('actor_type', 32)->default('HUMAN');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'event_type', 'occurred_at']);
        });

        Schema::table('broker_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('broker_accounts', 'fleet_provider_code')) {
                $table->string('fleet_provider_code', 64)->nullable()->after('platform');
            }
            if (! Schema::hasColumn('broker_accounts', 'fleet_safe_mode')) {
                $table->boolean('fleet_safe_mode')->default(false)->after('is_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('broker_accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('broker_accounts', 'fleet_safe_mode')) {
                $table->dropColumn('fleet_safe_mode');
            }
            if (Schema::hasColumn('broker_accounts', 'fleet_provider_code')) {
                $table->dropColumn('fleet_provider_code');
            }
        });

        $tables = [
            'fleet_audit_events',
            'trading_node_leases',
            'trading_nodes',
            'fx_valuation_rates',
            'automation_account_scopes',
            'fleet_emergency_controls',
            'fleet_health_snapshots',
            'fleet_reconciliation_runs',
            'execution_routes',
            'fleet_risk_locks',
            'strategy_assignments',
            'allocation_plans',
            'portfolio_memberships',
            'trading_portfolios',
            'instrument_mappings',
            'broker_instruments',
            'canonical_instruments',
            'fleet_terminals',
            'broker_capabilities',
            'account_fingerprints',
            'fleet_accounts',
            'broker_connections',
            'broker_providers',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
