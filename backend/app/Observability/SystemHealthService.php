<?php

namespace App\Observability;

use App\Enums\DependencyCriticality;
use App\Enums\SystemHealthStatus;
use App\Enums\TradingReadiness;
use App\Models\ServiceHeartbeat;
use App\Models\SystemHealthSnapshot;
use App\Observability\Support\ObservabilitySafety;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System health: HEALTHY/DEGRADED/UNHEALTHY/UNKNOWN.
 * CRITICAL vs OPTIONAL dependencies; history; heartbeat monitoring.
 * Stale CRITICAL heartbeat → blocks new entries.
 */
class SystemHealthService
{
    public const HEARTBEAT_STALE_SECONDS = 120;

    /** @var array<string, string> service => CRITICAL|OPTIONAL */
    public const DEPENDENCY_GRAPH = [
        'DATABASE' => 'CRITICAL',
        'MARKET_DATA' => 'CRITICAL',
        'RISK_ENGINE' => 'CRITICAL',
        'EXECUTION_ENGINE' => 'CRITICAL',
        'AUTOMATED_TRADING_ORCHESTRATOR' => 'CRITICAL',
        'TRADE_INTELLIGENCE' => 'OPTIONAL',
        'NEWS_CALENDAR' => 'OPTIONAL',
        'AI_PROVIDER' => 'OPTIONAL',
        'EMAIL_NOTIFICATIONS' => 'OPTIONAL',
        'TELEGRAM_NOTIFICATIONS' => 'OPTIONAL',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly StructuredLogger $logger,
    ) {}

    public function evaluate(): SystemHealthSnapshot
    {
        $dependencies = [];
        $criticalUnhealthy = 0;
        $optionalUnhealthy = 0;
        $unknown = 0;

        try {
            DB::select('select 1');
            $dependencies['DATABASE'] = [
                'status' => SystemHealthStatus::Healthy->value,
                'criticality' => DependencyCriticality::Critical->value,
                'detail' => 'connected',
            ];
        } catch (\Throwable $e) {
            $dependencies['DATABASE'] = [
                'status' => SystemHealthStatus::Unhealthy->value,
                'criticality' => DependencyCriticality::Critical->value,
                'detail' => 'unavailable',
            ];
            $criticalUnhealthy++;
        }

        foreach (self::DEPENDENCY_GRAPH as $service => $criticality) {
            if ($service === 'DATABASE') {
                continue;
            }
            if (in_array($service, ['EMAIL_NOTIFICATIONS', 'TELEGRAM_NOTIFICATIONS', 'AI_PROVIDER', 'NEWS_CALENDAR'], true)) {
                $dependencies[$service] = [
                    'status' => SystemHealthStatus::Healthy->value,
                    'criticality' => $criticality,
                    'detail' => 'optional_or_mock_ok',
                ];

                continue;
            }

            $hb = $this->heartbeatStatus($service);
            $dependencies[$service] = [
                'status' => $hb['status'],
                'criticality' => $criticality,
                'detail' => $hb['detail'],
                'last_heartbeat_at' => $hb['last_heartbeat_at'],
            ];
            if ($hb['status'] === SystemHealthStatus::Unhealthy->value && $criticality === 'CRITICAL') {
                $criticalUnhealthy++;
            } elseif ($hb['status'] === SystemHealthStatus::Unhealthy->value) {
                $optionalUnhealthy++;
            } elseif ($hb['status'] === SystemHealthStatus::Unknown->value && $criticality === 'CRITICAL') {
                $unknown++;
            }
        }

        $overall = SystemHealthStatus::Healthy;
        if ($criticalUnhealthy > 0) {
            $overall = SystemHealthStatus::Unhealthy;
        } elseif ($unknown > 0) {
            $overall = SystemHealthStatus::Unknown;
        } elseif ($optionalUnhealthy > 0) {
            $overall = SystemHealthStatus::Degraded;
        }

        $liveLocked = $this->settings->value('allow_live_execution') !== true;
        $emergency = $this->settings->value('emergency_stop') === true;
        $staleBlocks = $this->staleCriticalHeartbeats($dependencies);
        $newEntriesBlocked = $emergency || $staleBlocks || $overall === SystemHealthStatus::Unhealthy
            || $this->settings->value('allow_live_execution') === true;

        $blockReason = null;
        if ($this->settings->value('allow_live_execution') === true) {
            $blockReason = 'LIVE_SETTING_UNEXPECTED';
            $overall = SystemHealthStatus::Unhealthy;
        } elseif ($emergency) {
            $blockReason = 'EMERGENCY_STOP';
        } elseif ($staleBlocks) {
            $blockReason = 'STALE_CRITICAL_HEARTBEAT';
        } elseif ($overall === SystemHealthStatus::Unhealthy) {
            $blockReason = 'CRITICAL_DEPENDENCY_UNHEALTHY';
        }

        $tradingReadiness = TradingReadiness::NotReady;
        // LIVE always NOT_READY. DEMO trading readiness only when healthy + not blocked + demo flags.
        $demoOk = $overall === SystemHealthStatus::Healthy
            && ! $newEntriesBlocked
            && $liveLocked
            && $this->settings->value('allow_demo_execution') === true;
        if ($demoOk) {
            $tradingReadiness = TradingReadiness::Ready;
        }

        $snapshot = SystemHealthSnapshot::query()->create([
            'public_id' => (string) Str::uuid(),
            'overall_status' => $overall->value,
            'trading_readiness' => $tradingReadiness->value,
            'dependencies' => $dependencies,
            'checks' => [
                'live_locked' => $liveLocked,
                'emergency_stop' => $emergency,
                'stale_heartbeat_blocks_new_entries' => ObservabilitySafety::STALE_HEARTBEAT_BLOCKS_NEW_ENTRIES,
                'phase15_order_send' => ObservabilitySafety::ORDER_SEND_CALL_SITES_IN_PHASE_15,
                'live_auto_exists' => ObservabilitySafety::LIVE_AUTO_EXISTS,
                'allowed_environments' => ObservabilitySafety::ALLOWED_ENVIRONMENTS,
            ],
            'new_entries_blocked' => $newEntriesBlocked,
            'block_reason' => $blockReason,
            'observed_at' => now(),
        ]);

        $this->logger->info('SystemHealthService', 'Health evaluated', [
            'overall' => $overall->value,
            'trading_readiness' => $tradingReadiness->value,
            'new_entries_blocked' => $newEntriesBlocked,
        ]);

        return $snapshot;
    }

    public function history(int $limit = 50): array
    {
        return SystemHealthSnapshot::query()
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get()
            ->map(fn (SystemHealthSnapshot $s) => [
                'public_id' => $s->public_id,
                'overall_status' => $s->overall_status,
                'trading_readiness' => $s->trading_readiness,
                'new_entries_blocked' => $s->new_entries_blocked,
                'block_reason' => $s->block_reason,
                'observed_at' => $s->observed_at?->toIso8601String(),
            ])
            ->all();
    }

    public function blocksNewEntries(): bool
    {
        $latest = SystemHealthSnapshot::query()->latest('id')->first();
        if (! $latest) {
            $latest = $this->evaluate();
        }

        return (bool) $latest->new_entries_blocked;
    }

    /** @param  array<string, array<string, mixed>>  $dependencies */
    private function staleCriticalHeartbeats(array $dependencies): bool
    {
        foreach ($dependencies as $dep) {
            if (($dep['criticality'] ?? '') === 'CRITICAL'
                && ($dep['status'] ?? '') === SystemHealthStatus::Unhealthy->value
                && ($dep['detail'] ?? '') === 'stale_heartbeat') {
                return true;
            }
        }

        return false;
    }

    /** @return array{status: string, detail: string, last_heartbeat_at: ?string} */
    private function heartbeatStatus(string $service): array
    {
        $aliases = match ($service) {
            'RISK_ENGINE' => ['RISK_ENGINE', 'RISK'],
            'MARKET_DATA' => ['MARKET_DATA', 'MARKET_DATA_ENGINE'],
            default => [$service],
        };

        $hb = ServiceHeartbeat::query()
            ->whereIn('service', $aliases)
            ->latest('observed_at')
            ->first();

        if (! $hb || ! $hb->observed_at) {
            // Missing heartbeat → UNKNOWN (operator must start workers). Not the same as STALE.
            return [
                'status' => SystemHealthStatus::Unknown->value,
                'detail' => 'missing_heartbeat',
                'last_heartbeat_at' => null,
            ];
        }

        $age = now()->diffInSeconds($hb->observed_at);
        if ($age > self::HEARTBEAT_STALE_SECONDS) {
            // Stale heartbeat → UNHEALTHY and blocks new entries (CRITICAL deps).
            return [
                'status' => SystemHealthStatus::Unhealthy->value,
                'detail' => 'stale_heartbeat',
                'last_heartbeat_at' => $hb->observed_at->toIso8601String(),
            ];
        }

        return [
            'status' => SystemHealthStatus::Healthy->value,
            'detail' => 'fresh',
            'last_heartbeat_at' => $hb->observed_at->toIso8601String(),
        ];
    }
}
