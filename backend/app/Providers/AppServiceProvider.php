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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
    }
}
