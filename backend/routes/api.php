<?php

use App\Http\Controllers\Api\SimulationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/simulation')->group(function (): void {
    Route::get('/status', [SimulationController::class, 'status']);
    Route::post('/orders', [SimulationController::class, 'order']);
});
