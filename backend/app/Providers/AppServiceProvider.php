<?php

namespace App\Providers;

use App\Contracts\ExecutionAdapter;
use App\Contracts\MarketDataProvider;
use App\Contracts\PositionReconciliationService;
use App\Contracts\SimulationRepository;
use App\Repositories\InMemorySimulationRepository;
use App\Services\MockMarketDataProvider;
use App\Services\SimulationExecutionAdapter;
use App\Services\SimulationPositionReconciliationService;
use App\Strategies\StrategyRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SimulationRepository::class, InMemorySimulationRepository::class);
        $this->app->bind(MarketDataProvider::class, MockMarketDataProvider::class);
        $this->app->bind(ExecutionAdapter::class, SimulationExecutionAdapter::class);
        $this->app->bind(PositionReconciliationService::class, SimulationPositionReconciliationService::class);
        $this->app->singleton(\App\Execution\FakeDemoBridgeClient::class);
        $this->app->bind(\App\Contracts\DemoBridgeClient::class, function ($app) {
            if (config('trading_bridge.demo_client') === 'http' && filled(config('trading_bridge.service_token'))) {
                return $app->make(\App\Execution\TradingBridgeDemoClient::class);
            }

            return $app->make(\App\Execution\FakeDemoBridgeClient::class);
        });
        $this->app->singleton(StrategyRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
