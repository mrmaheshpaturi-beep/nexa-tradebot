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
                'adapter' => $bridgeConfigured ? 'MT5_READ_ONLY' : 'SIMULATION',
            ],
            'broker' => [
                'status' => $bridgeConfigured && $bridgeState === 'CONNECTED' ? 'READ_ONLY' : 'DISCONNECTED',
                'connected' => $bridgeConfigured && $bridgeState === 'CONNECTED',
                'mode' => 'READ_ONLY',
                'environment' => 'DEMO',
            ],
            'mt5_bridge' => [
                'configured' => $bridgeConfigured,
                'mode' => 'READ_ONLY',
                'environment' => 'DEMO',
                'state' => $bridgeState,
                'execution_available' => false,
            ],
            'execution' => ['available' => $riskReady, 'environment' => 'SIMULATION', 'broker_transmission' => false],
            'simulation_execution_enabled' => $simulationEnabled,
            'allow_demo_execution' => false,
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
