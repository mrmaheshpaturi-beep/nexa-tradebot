<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_symbols', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('symbol', 40);
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('digits')->nullable();
            $table->decimal('point', 18, 10)->nullable();
            $table->decimal('trade_tick_size', 18, 10)->nullable();
            $table->decimal('trade_tick_value', 18, 8)->nullable();
            $table->decimal('volume_min', 12, 4)->nullable();
            $table->decimal('volume_max', 12, 4)->nullable();
            $table->decimal('volume_step', 12, 4)->nullable();
            $table->string('source', 30);
            $table->string('environment', 20);
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->string('quality_status', 20)->default('UNKNOWN');
            $table->json('quality_issues')->nullable();
            $table->boolean('usable')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'source', 'environment'], 'market_symbols_unique');
            $table->index(['environment', 'usable']);
        });

        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('symbol', 40);
            $table->decimal('bid', 18, 8)->nullable();
            $table->decimal('ask', 18, 8)->nullable();
            $table->decimal('spread', 18, 8)->nullable();
            $table->decimal('last', 18, 8)->nullable();
            $table->decimal('volume', 18, 4)->nullable();
            $table->string('source', 30);
            $table->string('environment', 20);
            $table->string('freshness_status', 20)->default('UNKNOWN');
            $table->decimal('age_seconds', 12, 3)->nullable();
            $table->boolean('is_stale')->default(false);
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->string('quality_status', 20)->default('UNKNOWN');
            $table->json('quality_issues')->nullable();
            $table->boolean('usable')->default(false);
            $table->timestamp('source_timestamp')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'source', 'environment'], 'market_quotes_unique');
            $table->index(['environment', 'freshness_status', 'usable']);
        });

        Schema::create('market_candles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('symbol', 40);
            $table->string('timeframe', 10);
            $table->timestamp('open_time');
            $table->timestamp('close_time')->nullable();
            $table->decimal('open', 18, 8)->nullable();
            $table->decimal('high', 18, 8)->nullable();
            $table->decimal('low', 18, 8)->nullable();
            $table->decimal('close', 18, 8)->nullable();
            $table->unsignedInteger('tick_volume')->default(0);
            $table->string('source', 30);
            $table->string('environment', 20);
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->string('quality_status', 20)->default('UNKNOWN');
            $table->json('quality_issues')->nullable();
            $table->boolean('usable')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(
                ['symbol', 'timeframe', 'open_time', 'source', 'environment'],
                'market_candles_unique'
            );
            $table->index(['symbol', 'timeframe', 'environment', 'open_time']);
        });

        Schema::create('market_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('source', 30);
            $table->string('environment', 20);
            $table->unsignedInteger('symbol_count')->default(0);
            $table->unsignedInteger('quote_count')->default(0);
            $table->unsignedInteger('usable_quote_count')->default(0);
            $table->unsignedInteger('stale_quote_count')->default(0);
            $table->unsignedInteger('candle_count')->default(0);
            $table->unsignedTinyInteger('overall_quality_score')->default(0);
            $table->string('overall_quality_status', 20)->default('UNKNOWN');
            $table->string('candle_symbol', 40)->nullable();
            $table->string('candle_timeframe', 10)->nullable();
            $table->json('payload');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['environment', 'source', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
        Schema::dropIfExists('market_candles');
        Schema::dropIfExists('market_quotes');
        Schema::dropIfExists('market_symbols');
    }
};
