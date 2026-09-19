<?php

namespace App\Observability;

use App\Enums\AlertSeverity;
use App\Enums\SystemHealthStatus;
use App\Enums\WatchdogAction;
use App\Models\WatchdogEvent;
use App\Observability\Support\ObservabilitySafety;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * SystemWatchdog: LOG / ALERT / DEGRADE / PAUSE_NEW_ENTRIES / SAFE_MODE.
 * Never duplicates orders.
 */
class SystemWatchdog
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly AlertManager $alerts,
        private readonly SettingsService $settings,
        private readonly StructuredLogger $logger,
    ) {}

    public function inspect(): array
    {
        $snapshot = $this->health->evaluate();
        $action = WatchdogAction::Log;
        $reason = 'healthy';

        if ($this->settings->value('allow_live_execution') === true) {
            $action = WatchdogAction::SafeMode;
            $reason = 'LIVE_SETTING_DETECTED';
        } elseif ($snapshot->overall_status === SystemHealthStatus::Unhealthy->value) {
            $action = WatchdogAction::SafeMode;
            $reason = $snapshot->block_reason ?? 'UNHEALTHY';
        } elseif ($snapshot->new_entries_blocked) {
            $action = WatchdogAction::PauseNewEntries;
            $reason = $snapshot->block_reason ?? 'ENTRIES_BLOCKED';
        } elseif ($snapshot->overall_status === SystemHealthStatus::Degraded->value) {
            $action = WatchdogAction::Degrade;
            $reason = 'DEGRADED_OPTIONAL_DEPENDENCY';
        } elseif ($snapshot->overall_status === SystemHealthStatus::Unknown->value) {
            $action = WatchdogAction::Alert;
            $reason = 'UNKNOWN_CRITICAL_DEPENDENCY';
        }

        if ($action !== WatchdogAction::Log) {
            $this->alerts->raise(
                'WATCHDOG',
                'Watchdog action: '.$action->value,
                $action === WatchdogAction::SafeMode ? AlertSeverity::Emergency : AlertSeverity::Warning,
                $reason,
                ['action' => $action->value, 'duplicates_orders' => false],
            );
        }

        $event = WatchdogEvent::query()->create([
            'public_id' => (string) Str::uuid(),
            'action' => $action->value,
            'reason' => $reason,
            'context' => [
                'overall_status' => $snapshot->overall_status,
                'trading_readiness' => $snapshot->trading_readiness,
                'phase' => ObservabilitySafety::PHASE,
                'order_send' => false,
                'duplicates_orders' => false,
            ],
            'duplicates_orders' => false,
            'occurred_at' => now(),
        ]);

        $this->logger->info('SystemWatchdog', 'Watchdog inspect', [
            'action' => $action->value,
            'reason' => $reason,
            'event' => $event->public_id,
        ]);

        return [
            'action' => $action->value,
            'reason' => $reason,
            'duplicates_orders' => false,
            'new_entries_blocked' => (bool) $snapshot->new_entries_blocked,
            'health' => [
                'overall_status' => $snapshot->overall_status,
                'trading_readiness' => $snapshot->trading_readiness,
            ],
            'event_public_id' => $event->public_id,
        ];
    }
}
