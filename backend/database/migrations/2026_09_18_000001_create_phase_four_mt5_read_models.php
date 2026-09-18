<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mt5_bridge_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mode', 20)->default('REAL');
            $table->string('environment', 20)->default('DEMO');
            $table->string('status', 30)->default('UNCONFIGURED');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_stale_at')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'environment', 'status']);
        });

        Schema::create('mt5_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_bridge_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_account_id', 100);
            $table->string('currency', 10)->nullable();
            $table->unsignedInteger('leverage')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['mt5_bridge_connection_id', 'external_account_id'], 'mt5_mapping_external_unique');
            $table->unique(['mt5_bridge_connection_id', 'broker_account_id'], 'mt5_mapping_account_unique');
        });

        Schema::create('instrument_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_bridge_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_symbol', 40);
            $table->string('normalized_symbol', 40);
            $table->json('external_spec')->nullable();
            $table->timestamp('spec_observed_at')->nullable();
            $table->string('spec_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['mt5_bridge_connection_id', 'external_symbol'], 'instrument_alias_external_unique');
            $table->index(['trading_instrument_id', 'normalized_symbol']);
        });

        Schema::table('account_snapshots', function (Blueprint $table): void {
            $table->string('source', 30)->default('SIMULATION');
            $table->string('environment', 20)->default('SIMULATION');
            $table->string('external_snapshot_id', 100)->nullable();
            $table->unique(
                ['broker_account_id', 'source', 'external_snapshot_id'],
                'account_snapshot_external_unique'
            );
            $table->index(['broker_account_id', 'source', 'environment', 'captured_at'], 'snapshot_source_index');
        });

        Schema::create('mt5_external_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_account_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('symbol', 40);
            $table->string('side', 20)->nullable();
            $table->decimal('volume', 12, 4)->default(0);
            $table->decimal('price_open', 18, 8)->nullable();
            $table->decimal('price_current', 18, 8)->nullable();
            $table->decimal('profit', 18, 4)->default(0);
            $table->string('status', 20)->default('OPEN');
            $table->string('source', 20)->default('MT5');
            $table->string('environment', 20)->default('DEMO');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['mt5_account_mapping_id', 'external_id'], 'mt5_position_external_unique');
            $table->index(['mt5_account_mapping_id', 'status', 'last_seen_at'], 'mt5_position_state_index');
        });

        Schema::create('mt5_external_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_account_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('symbol', 40);
            $table->string('type', 30)->nullable();
            $table->string('state', 30)->nullable();
            $table->decimal('volume', 12, 4)->default(0);
            $table->decimal('price', 18, 8)->nullable();
            $table->string('source', 20)->default('MT5');
            $table->string('environment', 20)->default('DEMO');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['mt5_account_mapping_id', 'external_id'], 'mt5_order_external_unique');
            $table->index(['mt5_account_mapping_id', 'state', 'source_updated_at'], 'mt5_order_state_index');
        });

        Schema::create('mt5_external_deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_account_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('external_order_id', 100)->nullable();
            $table->string('symbol', 40);
            $table->string('entry', 20)->nullable();
            $table->decimal('volume', 12, 4)->default(0);
            $table->decimal('price', 18, 8)->nullable();
            $table->decimal('profit', 18, 4)->default(0);
            $table->string('source', 20)->default('MT5');
            $table->string('environment', 20)->default('DEMO');
            $table->timestamp('executed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['mt5_account_mapping_id', 'external_id'], 'mt5_deal_external_unique');
            $table->index(['mt5_account_mapping_id', 'executed_at'], 'mt5_deal_time_index');
        });

        Schema::create('mt5_sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_account_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 30);
            $table->string('cursor_value')->nullable();
            $table->timestamp('last_source_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['mt5_account_mapping_id', 'resource']);
        });

        Schema::create('mt5_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('mt5_account_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('RUNNING');
            $table->string('source', 20)->default('MT5');
            $table->string('environment', 20)->default('DEMO');
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['mt5_account_mapping_id', 'started_at']);
        });

        Schema::create('mt5_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mt5_reconciliation_run_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 30);
            $table->string('external_id', 100);
            $table->string('status', 30);
            $table->string('reason_code', 60)->nullable();
            $table->json('expected')->nullable();
            $table->json('observed')->nullable();
            $table->timestamps();
            $table->unique(
                ['mt5_reconciliation_run_id', 'resource_type', 'external_id'],
                'mt5_reconcile_item_unique'
            );
            $table->index(['mt5_reconciliation_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt5_reconciliation_items');
        Schema::dropIfExists('mt5_reconciliation_runs');
        Schema::dropIfExists('mt5_sync_cursors');
        Schema::dropIfExists('mt5_external_deals');
        Schema::dropIfExists('mt5_external_orders');
        Schema::dropIfExists('mt5_external_positions');
        Schema::table('account_snapshots', function (Blueprint $table): void {
            $table->dropUnique('account_snapshot_external_unique');
            $table->dropIndex('snapshot_source_index');
            $table->dropColumn(['source', 'environment', 'external_snapshot_id']);
        });
        Schema::dropIfExists('instrument_aliases');
        Schema::dropIfExists('mt5_account_mappings');
        Schema::dropIfExists('mt5_bridge_connections');
    }
};
