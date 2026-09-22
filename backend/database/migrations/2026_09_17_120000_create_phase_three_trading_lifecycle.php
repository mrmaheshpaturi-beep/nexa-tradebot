<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->string('symbol', 20)->unique();
            $table->string('name');
            $table->string('display_name');
            $table->string('asset_class', 30);
            $table->string('currency_base', 10);
            $table->string('currency_quote', 10);
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->unsignedSmallInteger('digits');
            $table->decimal('point_size', 18, 10);
            $table->decimal('contract_size', 18, 4);
            $table->decimal('tick_size', 18, 10);
            $table->decimal('tick_value', 18, 8);
            $table->decimal('volume_min', 12, 4);
            $table->decimal('volume_max', 12, 4);
            $table->decimal('volume_step', 12, 4);
            $table->decimal('minimum_volume', 12, 4);
            $table->decimal('maximum_volume', 12, 4);
            $table->decimal('step_volume', 12, 4);
            $table->decimal('minimum_stop_distance', 18, 10)->default(0);
            $table->decimal('margin_rate', 12, 8)->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['is_enabled', 'symbol']);
        });

        Schema::create('trading_terminals', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('platform', 30)->default('SIMULATION');
            $table->string('machine_identifier')->nullable();
            $table->string('environment', 20)->default('SIMULATION');
            $table->string('status', 20)->default('OFFLINE');
            $table->string('adapter', 40)->default('SIMULATION');
            $table->string('version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['environment', 'status']);
        });

        Schema::create('trading_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('trading_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20)->default('SIMULATION');
            $table->string('status', 20)->default('OFFLINE');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['broker_account_id', 'environment', 'status']);
        });

        Schema::create('service_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('trading_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service', 60);
            $table->string('instance_id', 100);
            $table->string('status', 20)->default('OFFLINE');
            $table->string('environment', 20)->default('SIMULATION');
            $table->timestamp('observed_at');
            $table->timestamp('last_seen_at');
            $table->json('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['service', 'environment', 'observed_at']);
        });

        Schema::table('broker_accounts', function (Blueprint $table): void {
            $table->string('public_id', 50)->nullable();
            $table->index(['user_id', 'environment', 'status']);
        });
        Schema::table('signals', function (Blueprint $table): void {
            $table->string('public_id', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('entry_reference', 18, 8)->nullable();
            $table->decimal('take_profit_1_reference', 18, 8)->nullable();
            $table->decimal('take_profit_2_reference', 18, 8)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->index(['user_id', 'environment', 'status', 'generated_at']);
        });

        DB::table('broker_accounts')->whereNull('public_id')->orderBy('id')->each(function (object $row): void {
            DB::table('broker_accounts')->where('id', $row->id)->update(['public_id' => (string) Str::uuid()]);
        });
        DB::table('signals')->whereNull('public_id')->orderBy('id')->each(function (object $row): void {
            DB::table('signals')->where('id', $row->id)->update([
                'public_id' => 'SIM-SIG-'.Str::upper((string) Str::ulid()),
                'status' => $row->status === 'NEW' ? 'GENERATED' : $row->status,
                'entry_reference' => $row->entry_price,
                'take_profit_1_reference' => $row->take_profit_1,
                'take_profit_2_reference' => $row->take_profit_2,
            ]);
        });

        Schema::table('broker_accounts', fn (Blueprint $table) => $table->unique('public_id'));
        Schema::table('signals', fn (Blueprint $table) => $table->unique('public_id'));

        Schema::create('trade_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('trading_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('trading_instrument_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('origin', 30)->default('MANUAL');
            $table->string('side', 10);
            $table->string('order_type', 20)->default('MARKET');
            $table->decimal('volume', 12, 4);
            $table->decimal('requested_volume', 12, 4);
            $table->decimal('requested_price', 18, 8)->nullable();
            $table->decimal('requested_entry', 18, 8)->nullable();
            $table->decimal('stop_loss', 18, 8)->nullable();
            $table->decimal('take_profit', 18, 8)->nullable();
            $table->decimal('take_profit_2', 18, 8)->nullable();
            $table->string('time_in_force', 20)->default('GTC');
            $table->string('comment', 255)->nullable();
            $table->decimal('risk_percent', 8, 4)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->string('environment', 20)->default('SIMULATION');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['broker_account_id', 'environment', 'status', 'created_at'], 'ti_broker_env_status_created_idx');
        });

        Schema::create('risk_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('trade_intent_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('risk_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('decision', 20);
            $table->string('reason_code', 50);
            $table->text('message');
            $table->text('reason');
            $table->decimal('risk_amount', 18, 4)->default(0);
            $table->decimal('requested_risk', 18, 4)->default(0);
            $table->decimal('approved_risk', 18, 4)->nullable();
            $table->decimal('requested_volume', 12, 4);
            $table->decimal('approved_volume', 12, 4)->nullable();
            $table->decimal('reward_risk', 12, 4)->nullable();
            $table->json('checks');
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->index(['status', 'evaluated_at']);
        });

        Schema::create('execution_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('broker_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->string('type', 30);
            $table->string('status', 20)->default('CREATED');
            $table->string('environment', 20)->default('SIMULATION');
            $table->string('symbol', 20);
            $table->string('side', 10)->nullable();
            $table->string('order_type', 20)->nullable();
            $table->decimal('volume', 12, 4)->nullable();
            $table->decimal('price', 18, 8)->nullable();
            $table->decimal('stop_loss', 18, 8)->nullable();
            $table->decimal('take_profit', 18, 8)->nullable();
            $table->timestamp('expiration')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('failure_code', 50)->nullable();
            $table->string('safe_error', 255)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['broker_account_id', 'environment', 'status', 'created_at'], 'ec_broker_env_status_created_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('public_id', 50)->change();
            $table->uuid('correlation_id')->nullable()->unique();
            $table->foreignId('execution_command_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('trading_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id')->nullable();
            $table->string('side', 10)->nullable();
            $table->string('order_type', 20)->nullable();
            $table->decimal('requested_volume', 12, 4)->default(0);
            $table->decimal('filled_volume', 12, 4)->default(0);
            $table->decimal('remaining_volume', 12, 4)->default(0);
            $table->decimal('fill_price', 18, 8)->nullable();
            $table->decimal('average_fill_price', 18, 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->index(['broker_account_id', 'environment', 'status', 'created_at'], 'orders_broker_env_status_created_idx');
            $table->index(['trade_intent_id', 'status']);
        });
        DB::table('orders')->orderBy('id')->each(function (object $row): void {
            DB::table('orders')->where('id', $row->id)->update([
                'correlation_id' => $row->public_id,
                'public_id' => 'SIM-ORD-'.Str::upper((string) Str::ulid()),
                'side' => $row->direction,
                'order_type' => $row->type,
                'requested_volume' => $row->volume,
                'remaining_volume' => $row->volume,
                'requested_at' => $row->created_at,
            ]);
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->string('public_id', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_position_id')->nullable();
            $table->string('side', 10)->nullable();
            $table->decimal('initial_volume', 12, 4)->default(0);
            $table->decimal('current_volume', 12, 4)->default(0);
            $table->decimal('average_entry_price', 18, 8)->nullable();
            $table->decimal('stop_loss', 18, 8)->nullable();
            $table->decimal('take_profit', 18, 8)->nullable();
            $table->decimal('realized_pnl', 18, 4)->default(0);
            $table->decimal('floating_pnl', 18, 4)->default(0);
            $table->decimal('unrealized_pnl', 18, 4)->default(0);
            $table->decimal('margin_used', 18, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['broker_account_id', 'environment', 'status', 'created_at'], 'positions_broker_env_status_created_idx');
            $table->index(['user_id', 'status', 'opened_at']);
        });

        DB::table('positions')->whereNull('public_id')->orderBy('id')->each(function (object $row): void {
            DB::table('positions')->where('id', $row->id)->update([
                'public_id' => 'SIM-POS-'.Str::upper((string) Str::ulid()),
                'side' => $row->direction,
                'initial_volume' => $row->volume,
                'current_volume' => $row->volume,
                'average_entry_price' => $row->open_price,
                'unrealized_pnl' => 0,
            ]);
        });
        Schema::table('positions', fn (Blueprint $table) => $table->unique('public_id'));

        Schema::table('execution_commands', function (Blueprint $table): void {
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->string('public_id', 50)->nullable();
            $table->foreignId('execution_command_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('ENTRY');
            $table->string('origin', 30)->default('SIMULATION');
            $table->string('external_deal_id')->nullable();
            $table->string('side', 10)->nullable();
            $table->decimal('swap', 18, 4)->default(0);
            $table->decimal('fee', 18, 4)->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['position_id', 'dealt_at']);
            $table->index(['broker_account_id', 'executed_at']);
        });
        DB::table('deals')->whereNull('public_id')->orderBy('id')->each(function (object $row): void {
            DB::table('deals')->where('id', $row->id)->update([
                'public_id' => 'SIM-DEAL-'.Str::upper((string) Str::ulid()),
                'side' => $row->direction,
                'executed_at' => $row->dealt_at,
                'created_at' => $row->dealt_at,
                'updated_at' => $row->dealt_at,
            ]);
        });
        Schema::table('deals', fn (Blueprint $table) => $table->unique('public_id'));

        Schema::create('position_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 50)->unique();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_command_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('origin', 30)->default('SIMULATION');
            $table->string('source', 30)->default('SIMULATION');
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->decimal('volume_before', 12, 4)->nullable();
            $table->decimal('volume_after', 12, 4)->nullable();
            $table->decimal('price', 18, 8)->nullable();
            $table->decimal('realized_pnl', 18, 4)->default(0);
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['position_id', 'occurred_at']);
        });

        Schema::table('account_snapshots', function (Blueprint $table): void {
            $table->unsignedInteger('open_positions')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('account_snapshots', fn (Blueprint $table) => $table->dropColumn('open_positions'));
        Schema::dropIfExists('position_events');

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['execution_command_id']);
            $table->dropUnique(['public_id']);
            $table->dropIndex(['position_id', 'dealt_at']);
            $table->dropIndex(['broker_account_id', 'executed_at']);
            $table->dropColumn([
                'public_id', 'execution_command_id', 'type', 'origin', 'external_deal_id', 'side',
                'swap', 'fee', 'executed_at', 'metadata', 'created_at', 'updated_at',
            ]);
        });
        Schema::table('execution_commands', fn (Blueprint $table) => $table->dropForeign(['position_id']));
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['trading_instrument_id']);
            $table->dropForeign(['trading_strategy_id']);
            $table->dropForeign(['signal_id']);
            $table->dropUnique(['public_id']);
            $table->dropIndex(['broker_account_id', 'environment', 'status', 'created_at']);
            $table->dropIndex(['user_id', 'status', 'opened_at']);
            $table->dropColumn([
                'public_id', 'user_id', 'trading_instrument_id', 'trading_strategy_id', 'signal_id',
                'external_position_id', 'side', 'initial_volume', 'current_volume', 'average_entry_price',
                'stop_loss', 'take_profit', 'realized_pnl', 'floating_pnl', 'unrealized_pnl',
                'opened_at', 'closed_at', 'margin_used', 'metadata',
            ]);
        });
        DB::table('orders')->orderBy('id')->each(function (object $row): void {
            DB::table('orders')->where('id', $row->id)->update(['public_id' => $row->correlation_id ?? (string) Str::uuid()]);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['execution_command_id']);
            $table->dropUnique(['correlation_id']);
            $table->dropForeign(['execution_command_id']);
            $table->dropForeign(['trading_instrument_id']);
            $table->dropForeign(['trading_strategy_id']);
            $table->dropForeign(['trade_intent_id']);
            $table->dropIndex(['broker_account_id', 'environment', 'status', 'created_at']);
            $table->dropIndex(['trade_intent_id', 'status']);
            $table->dropColumn([
                'execution_command_id', 'correlation_id', 'trading_instrument_id', 'trading_strategy_id', 'trade_intent_id',
                'external_order_id', 'side', 'order_type', 'requested_volume', 'filled_volume', 'remaining_volume', 'fill_price',
                'average_fill_price', 'metadata', 'requested_at', 'submitted_at', 'accepted_at',
                'filled_at', 'cancelled_at', 'rejected_at', 'expired_at', 'failed_at',
            ]);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->uuid('public_id')->change());
        Schema::dropIfExists('execution_commands');
        Schema::dropIfExists('risk_decisions');
        Schema::dropIfExists('trade_intents');
        DB::table('signals')->where('status', 'GENERATED')->update(['status' => 'NEW']);
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['broker_account_id']);
            $table->dropForeign(['trading_instrument_id']);
            $table->dropUnique(['public_id']);
            $table->dropIndex(['user_id', 'environment', 'status', 'generated_at']);
            $table->dropColumn([
                'public_id', 'user_id', 'broker_account_id', 'trading_instrument_id',
                'entry_reference', 'take_profit_1_reference', 'take_profit_2_reference', 'consumed_at',
            ]);
        });
        Schema::table('broker_accounts', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropIndex(['user_id', 'environment', 'status']);
            $table->dropColumn('public_id');
        });
        Schema::dropIfExists('service_heartbeats');
        Schema::dropIfExists('trading_sessions');
        Schema::dropIfExists('trading_terminals');
        Schema::dropIfExists('trading_instruments');
    }
};
