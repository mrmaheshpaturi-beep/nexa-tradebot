<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->string('theme', 20)->default('system');
            $table->boolean('sidebar_collapsed')->default(false);
            $table->string('default_dashboard')->default('overview');
            $table->json('favorite_symbols')->nullable();
            $table->string('default_timeframe', 10)->default('H1');
            $table->unsignedSmallInteger('table_page_size')->default(25);
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('INACTIVE');
            $table->boolean('is_default')->default(false);
            $table->decimal('max_risk_per_trade', 8, 4)->default(1);
            $table->decimal('max_lot_size', 12, 4)->default(1);
            $table->decimal('max_daily_loss', 8, 4)->default(4);
            $table->decimal('max_weekly_loss', 8, 4)->default(8);
            $table->decimal('max_drawdown', 8, 4)->default(12);
            $table->unsignedInteger('max_open_positions')->default(8);
            $table->decimal('max_open_risk', 8, 4)->default(6);
            $table->unsignedInteger('max_trades_per_day')->default(20);
            $table->unsignedInteger('max_consecutive_losses')->default(4);
            $table->decimal('min_margin_level', 10, 2)->default(300);
            $table->decimal('max_spread', 10, 2)->default(3);
            $table->decimal('max_slippage', 10, 2)->default(1.5);
            $table->decimal('min_reward_risk', 8, 4)->default(2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('broker_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('broker')->nullable();
            $table->string('platform')->default('NONE');
            $table->string('server')->nullable();
            $table->string('environment', 20)->default('SIMULATION');
            $table->string('account_reference')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('leverage')->default(1);
            $table->string('status', 20)->default('DISCONNECTED');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_connected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'account_reference']);
        });
        Schema::create('trading_strategies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('mode', 20)->default('MANUAL');
            $table->unsignedInteger('version')->default(1);
            $table->decimal('minimum_signal_score', 6, 3)->default(0.70);
            $table->json('symbols');
            $table->json('timeframes');
            $table->json('sessions')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('auto_trading_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('strategy_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['trading_strategy_id', 'key']);
        });
        Schema::create('strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('configuration');
            $table->text('change_summary')->nullable();
            $table->timestamps();
            $table->unique(['trading_strategy_id', 'version']);
        });
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('group', 30)->default('general');
            $table->json('value');
            $table->boolean('is_public')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category', 50)->default('SYSTEM');
            $table->string('severity', 20)->default('INFO');
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('module', 50);
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('description');
            $table->string('result', 20)->default('SUCCESS');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
        });
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 20)->default('INFO');
            $table->string('category');
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
        });
        Schema::create('account_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 18, 4)->default(0);
            $table->decimal('equity', 18, 4)->default(0);
            $table->decimal('margin', 18, 4)->default(0);
            $table->decimal('free_margin', 18, 4)->default(0);
            $table->decimal('margin_level', 12, 4)->nullable();
            $table->decimal('floating_pnl', 18, 4)->default(0);
            $table->decimal('drawdown', 8, 4)->default(0);
            $table->timestamp('captured_at');
            $table->index(['broker_account_id', 'captured_at']);
        });
        Schema::create('signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 20);
            $table->string('direction', 10);
            $table->string('timeframe', 10)->nullable();
            $table->decimal('score', 6, 3)->nullable();
            $table->decimal('entry_price', 18, 8)->nullable();
            $table->decimal('stop_loss', 18, 8)->nullable();
            $table->decimal('take_profit_1', 18, 8)->nullable();
            $table->decimal('take_profit_2', 18, 8)->nullable();
            $table->decimal('risk_reward', 8, 4)->nullable();
            $table->string('market_regime', 30)->nullable();
            $table->string('status', 20)->default('NEW');
            $table->string('source', 50)->default('SIMULATION');
            $table->text('explanation')->nullable();
            $table->string('environment', 20)->default('SIMULATION');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('command_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('symbol', 20);
            $table->string('direction', 10);
            $table->string('type', 20)->default('MARKET');
            $table->decimal('volume', 12, 4);
            $table->decimal('requested_price', 18, 8)->nullable();
            $table->decimal('risk_amount', 18, 4)->nullable();
            $table->decimal('risk_percent', 8, 4)->nullable();
            $table->decimal('stop_loss', 18, 8)->nullable();
            $table->decimal('take_profit', 18, 8)->nullable();
            $table->string('comment', 255)->nullable();
            $table->string('status', 20)->default('SIMULATED');
            $table->string('environment', 20)->default('SIMULATION');
            $table->boolean('simulated')->default(true);
            $table->boolean('broker_transmitted')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::create('deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 20);
            $table->string('direction', 10);
            $table->decimal('volume', 12, 4);
            $table->decimal('price', 18, 8);
            $table->decimal('commission', 18, 4)->default(0);
            $table->decimal('profit', 18, 4)->default(0);
            $table->string('environment', 20)->default('SIMULATION');
            $table->timestamp('dealt_at');
        });
        Schema::create('positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opening_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->string('symbol', 20);
            $table->string('direction', 10);
            $table->decimal('volume', 12, 4);
            $table->decimal('open_price', 18, 8);
            $table->decimal('current_price', 18, 8)->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->string('environment', 20)->default('SIMULATION');
            $table->timestamps();
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('trades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('symbol', 20);
            $table->decimal('entry_price', 18, 8);
            $table->decimal('exit_price', 18, 8)->nullable();
            $table->decimal('profit', 18, 4)->default(0);
            $table->string('environment', 20)->default('SIMULATION');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('risk_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule');
            $table->string('severity', 20);
            $table->string('decision', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'risk_events', 'trades', 'positions', 'deals', 'orders', 'signals',
            'account_snapshots', 'system_events', 'audit_logs', 'notifications',
            'application_settings', 'strategy_versions', 'strategy_settings',
            'trading_strategies', 'broker_accounts', 'risk_profiles',
            'user_preferences', 'permission_role', 'role_user', 'permissions', 'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
