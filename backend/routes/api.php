<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrokerAccountController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\RiskProfileController;
use App\Http\Controllers\Api\ServiceHeartbeatController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\SimulationOrderController;
use App\Http\Controllers\Api\StrategyController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TradeIntentController;
use App\Http\Controllers\Api\TradingInstrumentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('web')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/password/request', [PasswordResetController::class, 'request'])->middleware('throttle:3,1');
    Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');
    Route::get('/system/status', [SystemController::class, 'status']);
    Route::get('/simulation/status', [SystemController::class, 'status']);

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [SystemController::class, 'dashboard'])->middleware('permission:dashboard.view');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:users.status');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:users.status');
        Route::post('/users/{user}/disable', [UserController::class, 'disable'])->middleware('permission:users.status');

        Route::get('/strategies', [StrategyController::class, 'index'])->middleware('permission:strategies.view');
        Route::post('/strategies', [StrategyController::class, 'store'])->middleware('permission:strategies.create');
        Route::put('/strategies/{strategy}', [StrategyController::class, 'update'])->middleware('permission:strategies.update');

        Route::get('/risk-profiles', [RiskProfileController::class, 'index'])->middleware('permission:risk_profiles.view');
        Route::post('/risk-profiles', [RiskProfileController::class, 'store'])->middleware('permission:risk_profiles.create');
        Route::put('/risk-profiles/{riskProfile}', [RiskProfileController::class, 'update'])->middleware('permission:risk_profiles.update');

        Route::get('/broker-accounts', [BrokerAccountController::class, 'index'])->middleware('permission:broker_accounts.view');
        Route::post('/broker-accounts', [BrokerAccountController::class, 'store'])->middleware('permission:broker_accounts.create');
        Route::put('/broker-accounts/{brokerAccount}', [BrokerAccountController::class, 'update'])->middleware('permission:broker_accounts.update');

        Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
        Route::put('/settings/{key}', [SettingController::class, 'update'])->middleware('permission:settings.update');
        Route::put('/emergency-stop', [SettingController::class, 'emergencyStop'])->middleware('permission:emergency_stop.manage');
        Route::get('/preferences', [UserPreferenceController::class, 'show'])->middleware('permission:preferences.view');
        Route::put('/preferences', [UserPreferenceController::class, 'update'])->middleware('permission:preferences.update');
        Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('permission:notifications.update');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view');
        Route::post('/simulation/orders', [SimulationOrderController::class, 'store'])->middleware('permission:simulation_orders.create');

        Route::get('/instruments', [TradingInstrumentController::class, 'index'])->middleware('permission:trading.read');
        Route::get('/instruments/{instrument}', [TradingInstrumentController::class, 'show'])->middleware('permission:trading.read');
        Route::get('/signals', [SignalController::class, 'index'])->middleware('permission:signals.view');
        Route::get('/signals/{signal}', [SignalController::class, 'show'])->middleware('permission:signals.view');
        Route::post('/signals/{signal}/trade-intent', [SignalController::class, 'createIntent'])->middleware('permission:simulation_lifecycle.create');

        Route::get('/trade-intents', [TradeIntentController::class, 'index'])->middleware('permission:trading.read');
        Route::post('/trade-intents', [TradeIntentController::class, 'store'])->middleware('permission:simulation_lifecycle.create');
        Route::get('/trade-intents/{tradeIntent}', [TradeIntentController::class, 'show'])->middleware('permission:trading.read');
        Route::post('/trade-intents/{tradeIntent}/evaluate', [TradeIntentController::class, 'evaluate'])->middleware('permission:simulation_lifecycle.evaluate');
        Route::post('/trade-intents/{tradeIntent}/execute', [TradeIntentController::class, 'execute'])->middleware('permission:simulation_lifecycle.execute');

        Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:trading.read');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('permission:trading.read');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:simulation_orders.cancel');
        Route::get('/positions', [PositionController::class, 'index'])->middleware('permission:trading.read');
        Route::get('/positions/{position}', [PositionController::class, 'show'])->middleware('permission:trading.read');
        Route::post('/positions/{position}/close', [PositionController::class, 'close'])->middleware('permission:simulation_positions.manage');
        Route::post('/positions/{position}/partial-close', [PositionController::class, 'partialClose'])->middleware('permission:simulation_positions.manage');
        Route::put('/positions/{position}/stop-loss', [PositionController::class, 'modifyStopLoss'])->middleware('permission:simulation_positions.manage');
        Route::put('/positions/{position}/take-profit', [PositionController::class, 'modifyTakeProfit'])->middleware('permission:simulation_positions.manage');
        Route::put('/positions/{position}/protection', [PositionController::class, 'modifyProtection'])->middleware('permission:simulation_positions.manage');
        Route::get('/heartbeats', [ServiceHeartbeatController::class, 'index'])->middleware('permission:trading.read');
    });
});
