<?php

namespace App\Providers;

use App\Contracts\SimulationRepository;
use App\Repositories\InMemorySimulationRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SimulationRepository::class, InMemorySimulationRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
