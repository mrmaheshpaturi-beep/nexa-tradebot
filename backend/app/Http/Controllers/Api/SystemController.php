<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemEvent;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function status(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'CONNECTED';
        } catch (QueryException) {
            $database = 'UNAVAILABLE';
        }

        $emergencyStop = $this->settings->value('emergency_stop');

        return response()->json(['data' => [
            'environment' => 'SIMULATION',
            'database' => ['status' => $database, 'source' => 'DATABASE'],
            'market_data' => ['status' => 'MOCK', 'source' => 'MOCK MARKET DATA'],
            'simulation_engine' => ['status' => $emergencyStop ? 'STOPPED' : 'READY', 'source' => 'SIMULATION ENGINE'],
            'broker' => ['status' => 'DISCONNECTED', 'connected' => false],
            'execution' => ['available' => false, 'broker_transmission' => false],
            'allow_demo_execution' => false,
            'allow_live_execution' => false,
            'emergency_stop' => $emergencyStop,
            'trading_enabled' => $this->settings->value('trading_enabled'),
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $latestSnapshot = $request->user()->brokerAccounts()
            ->with(['snapshots' => fn ($query) => $query->latest('captured_at')->limit(1)])
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
