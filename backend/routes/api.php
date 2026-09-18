<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrokerAccountController;
use App\Http\Controllers\Api\IndicatorController;
use App\Http\Controllers\Api\MarketDataController;
use App\Http\Controllers\Api\Mt5BridgeController;
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
use App\Http\Controllers\Api\StrategyEngineController;
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
        Route::get('/strategies/{strategy}', [StrategyController::class, 'show'])->middleware('permission:strategies.view');
        Route::put('/strategies/{strategy}', [StrategyController::class, 'update'])->middleware('permission:strategies.update');
        Route::post('/strategies/{strategy}/enable', [StrategyController::class, 'enable'])->middleware('permission:strategies.update');
        Route::post('/strategies/{strategy}/disable', [StrategyController::class, 'disable'])->middleware('permission:strategies.update');
        Route::post('/strategies/{strategy}/evaluate', [StrategyEngineController::class, 'evaluate'])->middleware('permission:strategies.view');
        Route::get('/strategies/{strategy}/performance', [StrategyEngineController::class, 'performance'])->middleware('permission:strategies.view');

        Route::get('/strategy-engine/catalog', [StrategyEngineController::class, 'catalog'])->middleware('permission:strategies.view');
        Route::get('/strategy-engine/health', [StrategyEngineController::class, 'health'])->middleware('permission:trading.read');
        Route::post('/strategy-engine/run', [StrategyEngineController::class, 'run'])->middleware('permission:strategies.update');
        Route::post('/strategy-engine/scan', [StrategyEngineController::class, 'scan'])->middleware('permission:strategies.view');
        Route::get('/strategy-engine/matrix', [StrategyEngineController::class, 'matrix'])->middleware('permission:strategies.view');
        Route::post('/strategy-engine/confluence', [StrategyEngineController::class, 'confluence'])->middleware('permission:strategies.view');

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
        Route::post('/signals/expire-due', [SignalController::class, 'expireDue'])->middleware('permission:signals.view');
        Route::get('/signals/{signal}', [SignalController::class, 'show'])->middleware('permission:signals.view');
        Route::post('/signals/{signal}/trade-intent', [SignalController::class, 'createIntent'])->middleware('permission:simulation_lifecycle.create');
        Route::post('/signals/{signal}/invalidate', [SignalController::class, 'invalidate'])->middleware('permission:signals.view');

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

        Route::get('/market/snapshot', [MarketDataController::class, 'snapshot'])->middleware('permission:trading.read');
        Route::get('/market/quotes', [MarketDataController::class, 'quotes'])->middleware('permission:trading.read');
        Route::get('/market/quotes/{symbol}', [MarketDataController::class, 'quote'])->middleware('permission:trading.read');
        Route::get('/market/candles/{symbol}', [MarketDataController::class, 'candles'])->middleware('permission:trading.read');
        Route::get('/market/candles/{symbol}/closed', [MarketDataController::class, 'closedCandles'])->middleware('permission:trading.read');
        Route::get('/market/symbols', [MarketDataController::class, 'symbols'])->middleware('permission:trading.read');
        Route::get('/market/sessions', [MarketDataController::class, 'sessions'])->middleware('permission:trading.read');
        Route::get('/market/status/{symbol}', [MarketDataController::class, 'status'])->middleware('permission:trading.read');
        Route::get('/market/health', [MarketDataController::class, 'health'])->middleware('permission:trading.read');
        Route::get('/market/snapshots/latest', [MarketDataController::class, 'latestPersisted'])->middleware('permission:trading.read');
        Route::get('/market/monitored', [MarketDataController::class, 'monitored'])->middleware('permission:trading.read');
        Route::put('/market/monitored', [MarketDataController::class, 'monitored'])->middleware('permission:market.configure');
        Route::post('/market/symbols/sync', [MarketDataController::class, 'syncSymbols'])->middleware('permission:market.configure');
        Route::post('/market/backfill', [MarketDataController::class, 'backfill'])->middleware('permission:market.configure');
        Route::post('/market/quality-check', [MarketDataController::class, 'qualityCheck'])->middleware('permission:trading.read');
        Route::get('/market/extension-hooks', [MarketDataController::class, 'extensionHooks'])->middleware('permission:trading.read');

        Route::get('/indicators/catalog', [IndicatorController::class, 'catalog'])->middleware('permission:trading.read');
        Route::get('/indicators/health', [IndicatorController::class, 'health'])->middleware('permission:trading.read');
        Route::post('/indicators/compute', [IndicatorController::class, 'compute'])->middleware('permission:trading.read');
        Route::post('/indicators/batch', [IndicatorController::class, 'batch'])->middleware('permission:trading.read');
        Route::get('/indicators/{indicator}/series', [IndicatorController::class, 'series'])->middleware('permission:trading.read');

        Route::get('/mt5/status', [Mt5BridgeController::class, 'status'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/health', [Mt5BridgeController::class, 'proxyHealth'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/terminal', [Mt5BridgeController::class, 'proxyTerminal'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/account', [Mt5BridgeController::class, 'proxyAccount'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/symbols', [Mt5BridgeController::class, 'proxySymbols'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/symbols/{symbol}', [Mt5BridgeController::class, 'proxySymbol'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/quotes/{symbol}', [Mt5BridgeController::class, 'proxyQuote'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/candles/{symbol}', [Mt5BridgeController::class, 'proxyCandles'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/positions', [Mt5BridgeController::class, 'proxyPositions'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/orders', [Mt5BridgeController::class, 'proxyOrders'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/history/orders', [Mt5BridgeController::class, 'proxyHistoryOrders'])->middleware('permission:mt5.read');
        Route::get('/mt5/bridge/history/deals', [Mt5BridgeController::class, 'proxyHistoryDeals'])->middleware('permission:mt5.read');
        Route::get('/mt5/connections', [Mt5BridgeController::class, 'connections'])->middleware('permission:mt5.read');
        Route::post('/mt5/connections', [Mt5BridgeController::class, 'storeConnection'])->middleware('permission:mt5.connections.manage');
        Route::post('/mt5/connections/{connection}/test', [Mt5BridgeController::class, 'testConnection'])->middleware('permission:mt5.connections.manage');
        Route::post('/mt5/connections/{connection}/sync', [Mt5BridgeController::class, 'syncConnection'])->middleware('permission:mt5.sync');
        Route::get('/mt5/mappings/{mapping}/positions', [Mt5BridgeController::class, 'mappingPositions'])->middleware('permission:mt5.read');
        Route::get('/mt5/mappings/{mapping}/orders', [Mt5BridgeController::class, 'mappingOrders'])->middleware('permission:mt5.read');
        Route::get('/mt5/mappings/{mapping}/deals', [Mt5BridgeController::class, 'mappingDeals'])->middleware('permission:mt5.read');
        Route::post('/mt5/mappings/{mapping}/reconcile', [Mt5BridgeController::class, 'reconcileMapping'])->middleware('permission:mt5.reconcile');
        Route::get('/mt5/reconciliation-runs', [Mt5BridgeController::class, 'reconciliationRuns'])->middleware('permission:mt5.read');
        Route::get('/mt5/reconciliation-runs/{run}', [Mt5BridgeController::class, 'reconciliationRun'])->middleware('permission:mt5.read');
    });
});
