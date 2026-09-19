<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ServiceHeartbeat;
use App\Models\SystemEvent;
use App\Services\SettingsService;
use App\Services\TradingBridgeClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TradingBridgeClient $bridge,
    ) {}

    public function status(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'CONNECTED';
        } catch (QueryException) {
            $database = 'UNAVAILABLE';
        }

        $emergencyStop = $this->settings->value('emergency_stop');
        $simulationEnabled = $this->settings->value('simulation_execution_enabled') === true;
        $riskReady = ! $emergencyStop
            && $simulationEnabled;
        $heartbeat = ServiceHeartbeat::where('service', 'SIMULATION_ENGINE')->latest('observed_at')->first();
        $bridgeConfigured = $this->bridge->configured();
        $bridgeState = Cache::get('mt5_bridge:last_state', 'UNKNOWN');

        return response()->json(['data' => [
            'environment' => 'SIMULATION',
            'web_application' => ['status' => 'ONLINE'],
            'database' => ['status' => $database, 'source' => 'DATABASE'],
            'authentication' => ['status' => 'ONLINE'],
            'market_data' => [
                'status' => 'ENGINE_READY',
                'source' => $bridgeConfigured ? 'BRIDGE_OR_MOCK' : 'MOCK MARKET DATA',
                'engine' => 'PHASE_5',
                'freshness_validation' => true,
                'quality_scoring' => true,
                'read_only' => true,
            ],
            'market_data_engine' => [
                'phase' => 5,
                'status' => 'READY',
                'snapshot_api' => '/api/v1/market/snapshot',
                'phase_6_indicator_engine' => 'READY',
                'phase_7_strategies' => 'READY',
            ],
            'indicator_engine' => [
                'phase' => 6,
                'status' => 'READY',
                'catalog_api' => '/api/v1/indicators/catalog',
                'compute_api' => '/api/v1/indicators/compute',
                'read_only' => true,
                'phase_7_strategies' => 'READY',
            ],
            'strategy_engine' => [
                'phase' => 7,
                'status' => 'READY',
                'catalog_api' => '/api/v1/strategy-engine/catalog',
                'plugins' => 12,
                'analysis_only' => true,
                'auto_trading' => 'DISABLED',
                'order_send' => false,
            ],
            'market_scanner' => [
                'phase' => 8,
                'status' => 'READY',
                'board_api' => '/api/v1/scanner/board',
                'run_api' => '/api/v1/scanner/run',
                'analysis_only' => true,
                'broker_routing' => false,
                'order_send' => false,
                'auto_trading' => 'DISABLED',
            ],
            'signal_orchestrator' => [
                'phase' => 8,
                'status' => 'READY',
                'queue_api' => '/api/v1/scanner/queue',
                'mode' => 'CANDIDATES_ONLY',
                'broker_routing' => false,
            ],
            'risk_engine' => [
                'phase' => 9,
                'status' => 'READY',
                'authoritative' => true,
                'fail_closed' => true,
                'engine_version' => 'RiskEngine/v1',
                'dashboard_api' => '/api/v1/risk-engine/dashboard',
                'order_send' => false,
                'demo_execution' => false,
                'live_execution' => false,
                'broker_routable' => false,
            ],
            'execution_engine' => [
                'phase' => 10,
                'status' => 'READY',
                'demo_execution' => $this->settings->value('allow_demo_execution') === true
                    ? 'ENABLED_MANUAL_CONFIRM'
                    : 'DISABLED_AS_CONFIGURED',
                'live_execution' => 'HARD_FAIL',
                'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
                'order_send' => 'AUTHORIZED_DEMO_PATH_ONLY',
                'order_send_location' => 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
                'two_step_confirmation' => true,
                'dashboard_api' => '/api/v1/execution/dashboard',
            ],
            'trade_management_engine' => [
                'phase' => 11,
                'status' => 'READY',
                'demo_only' => true,
                'live_execution' => 'HARD_BLOCKED',
                'order_send' => 'AUTHORIZED_DEMO_PATH_ONLY',
            ],
            'analytics_engine' => [
                'phase' => 12,
                'status' => 'READY',
                'dashboard_api' => '/api/v1/analytics/dashboard',
                'order_send' => false,
                'broker_changing_calls' => 0,
                'auto_promote_strategies' => false,
                'auto_promote_risk' => false,
                'live_execution' => 'HARD_BLOCKED',
            ],
            'backtest_engine' => [
                'phase' => 12,
                'status' => 'READY',
                'environment' => 'BACKTEST',
                'console_api' => '/api/v1/backtest/runs',
                'order_send' => false,
                'broker_changing_calls' => 0,
                'demo_substitution' => false,
                'auto_promote' => false,
                'live_execution' => 'HARD_BLOCKED',
            ],
            'trade_intelligence_engine' => [
                'phase' => 13,
                'status' => 'READY',
                'mode' => 'ADVISORY_SHADOW',
                'desk_api' => '/api/v1/intelligence/desk',
                'order_send' => false,
                'mutation_tools' => false,
                'live_execution' => 'HARD_BLOCKED',
                'ai_provider_default' => 'MOCK',
                'advisory_only' => true,
            ],
            'automated_trading_orchestrator' => [
                'phase' => 14,
                'status' => 'READY',
                'default_state' => 'OFF',
                'auto_start_on_boot' => false,
                'modes' => ['OFF', 'DRY_RUN', 'DEMO_AUTO'],
                'live_auto_exists' => false,
                'ui_label' => 'AUTO DEMO',
                'control_center_api' => '/api/v1/automation/control-center',
                'order_send_phase14' => 0,
                'order_send_location' => 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
                'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
            ],
            'observability' => [
                'phase' => 15,
                'status' => 'READY',
                'order_send_phase15' => 0,
                'ai_execution' => 0,
                'live_auto_exists' => false,
                'allowed_environments' => ['LOCAL', 'STAGING', 'DEMO_VPS'],
                'live_production' => 'DOES_NOT_EXIST',
                'operations_api' => '/api/v1/observability/operations',
                'health_api' => '/api/v1/health',
                'trading_readiness_api' => '/api/v1/health/trading-readiness',
                'validation_lab_api' => '/api/v1/observability/validation-lab',
                'safe_for_real_money' => 'NO_AUTOMATIC_DECLARATION',
            ],
            'alert_pipeline' => [
                'phase' => 8,
                'status' => 'FOUNDATION_READY',
                'channels' => ['IN_APP', 'HOOK'],
                'email' => 'NOT_IMPLEMENTED',
                'sms' => 'NOT_IMPLEMENTED',
            ],
            'trading_engine' => ['status' => $riskReady ? 'READY' : 'STOPPED', 'mode' => 'SIMULATION'],
            'signal_engine' => ['status' => 'READY', 'phase' => 7, 'mode' => 'ANALYSIS_AND_SIGNALS_ONLY'],
            'risk_execution' => ['status' => $riskReady ? 'READY' : 'STOPPED', 'mode' => 'SIMULATION_ONLY'],
            'simulation_engine' => [
                'status' => $riskReady ? 'READY' : 'STOPPED',
                'source' => 'SIMULATION ENGINE',
                'last_heartbeat_at' => $heartbeat?->observed_at,
            ],
            'terminal' => [
                'status' => $bridgeConfigured && $bridgeState === 'CONNECTED' ? 'ONLINE' : 'OFFLINE',
                'adapter' => $bridgeConfigured ? 'MT5_DEMO_CAPABLE' : 'SIMULATION',
            ],
            'broker' => [
                'status' => $bridgeConfigured && $bridgeState === 'CONNECTED' ? 'DEMO_GATED' : 'DISCONNECTED',
                'connected' => $bridgeConfigured && $bridgeState === 'CONNECTED',
                'mode' => $this->settings->value('allow_demo_execution') === true ? 'DEMO_MANUAL' : 'READ_ONLY',
                'environment' => 'DEMO',
            ],
            'mt5_bridge' => [
                'configured' => $bridgeConfigured,
                'mode' => $this->settings->value('allow_demo_execution') === true ? 'DEMO_WRITE_GATED' : 'READ_ONLY',
                'environment' => 'DEMO',
                'state' => $bridgeState,
                'execution_available' => $this->settings->value('allow_demo_execution') === true,
            ],
            'execution' => [
                'available' => $riskReady || $this->settings->value('allow_demo_execution') === true,
                'environment' => $this->settings->value('allow_demo_execution') === true ? 'SIMULATION_OR_DEMO' : 'SIMULATION',
                'broker_transmission' => $this->settings->value('allow_demo_execution') === true,
            ],
            'simulation_execution_enabled' => $simulationEnabled,
            'allow_demo_execution' => $this->settings->value('allow_demo_execution') === true,
            'auto_demo_execution' => $this->settings->value('auto_demo_execution') === true,
            'allow_live_execution' => false,
            'emergency_stop' => $emergencyStop,
            'trading_enabled' => $this->settings->value('trading_enabled'),
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $latestSnapshot = $request->user()->brokerAccounts()
            ->with(['snapshots' => fn ($query) => $query->latest('captured_at')->latest('id')->limit(1)])
            ->get()
            ->flatMap->snapshots
            ->sortByDesc('captured_at')
            ->first();

        return response()->json(['data' => [
            'strategies' => $request->user()->strategies()->count(),
            'broker_accounts' => $request->user()->brokerAccounts()->count(),
            'simulation_orders' => $request->user()->orders()->count(),
            'unread_notifications' => $request->user()->notifications()->where('is_read', false)->count(),
            'latest_account_snapshot' => $latestSnapshot,
            'system_events' => SystemEvent::count(),
            'audit_logs' => AuditLog::count(),
        ]]);
    }
}
