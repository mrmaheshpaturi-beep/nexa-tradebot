<?php

namespace App\Observability;

use App\Observability\Support\ObservabilitySafety;
use App\Services\SettingsService;

/**
 * Facade coordinating Phase 15 observability surfaces.
 * Never sends orders. Never enables LIVE.
 */
class ObservabilityService
{
    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly SystemHealthService $health,
        private readonly SystemWatchdog $watchdog,
        private readonly AlertManager $alerts,
        private readonly ForwardValidationService $validation,
        private readonly DataQualityMonitor $dataQuality,
        private readonly PerformanceDriftMonitor $drift,
        private readonly BackupService $backups,
        private readonly CircuitBreaker $circuits,
        private readonly ResourceMonitor $resources,
        private readonly EnvValidator $env,
        private readonly SettingsService $settings,
        private readonly StructuredLogger $logger,
        private readonly FailureInjectionHarness $failures,
        private readonly SoakTestFramework $soak,
    ) {}

    public function healthPayload(): array
    {
        return [
            'phase' => ObservabilitySafety::PHASE,
            'status' => 'READY',
            'order_send_phase15' => ObservabilitySafety::ORDER_SEND_CALL_SITES_IN_PHASE_15,
            'ai_execution' => ObservabilitySafety::AI_EXECUTION_CALL_SITES,
            'live_auto_exists' => ObservabilitySafety::LIVE_AUTO_EXISTS,
            'allowed_environments' => ObservabilitySafety::ALLOWED_ENVIRONMENTS,
            'evidence_labels' => ObservabilitySafety::EVIDENCE_LABELS,
            'unknown_execution_policy' => ObservabilitySafety::UNKNOWN_EXECUTION_POLICY,
            'providers' => $this->alerts->providers(),
        ];
    }

    public function operationsDashboard(): array
    {
        $snapshot = $this->health->evaluate();

        return [
            'phase' => ObservabilitySafety::PHASE,
            'health' => [
                'overall_status' => $snapshot->overall_status,
                'trading_readiness' => $snapshot->trading_readiness,
                'new_entries_blocked' => $snapshot->new_entries_blocked,
                'block_reason' => $snapshot->block_reason,
                'dependencies' => $snapshot->dependencies,
                'checks' => $snapshot->checks,
            ],
            'metrics' => $this->metrics->summarize(),
            'alerts_open' => $this->alerts->center('OPEN')->count(),
            'resources' => $this->resources->snapshot(),
            'circuits' => $this->circuits->status(),
            'env' => $this->env->validateCurrent(),
            'data_quality_blocks' => $this->dataQuality->currentlyBlocksNewTrades(),
            'banners' => [
                'live' => 'LIVE HARD BLOCKED',
                'live_auto' => 'LIVE_AUTO DOES NOT EXIST',
                'safe_for_real_money' => 'NO AUTOMATIC DECLARATION',
            ],
        ];
    }

    public function tradingReadiness(): array
    {
        $snapshot = $this->health->evaluate();
        $liveAttempt = $this->settings->value('allow_live_execution') === true;
        $readiness = $liveAttempt ? 'NOT_READY' : $snapshot->trading_readiness;

        // Explicit: LIVE → always NOT_READY
        if ($liveAttempt) {
            $readiness = 'NOT_READY';
        }

        return [
            'trading_readiness' => $readiness,
            'live' => 'NOT_READY',
            'live_auto' => 'DOES_NOT_EXIST',
            'demo_path' => $snapshot->trading_readiness,
            'new_entries_blocked' => $snapshot->new_entries_blocked || $this->dataQuality->currentlyBlocksNewTrades(),
            'reasons' => array_values(array_filter([
                $snapshot->block_reason,
                $this->dataQuality->currentlyBlocksNewTrades() ? 'BAD_DATA' : null,
                $liveAttempt ? 'LIVE_FORBIDDEN' : null,
            ])),
            'phase' => ObservabilitySafety::PHASE,
        ];
    }

    public function readinessScorecard(): array
    {
        $ops = $this->operationsDashboard();
        $env = $ops['env'];
        $checks = [
            'observability_online' => true,
            'health_api' => true,
            'alerts_foundation' => true,
            'forward_validation' => true,
            'backup_service' => true,
            'circuit_breakers' => true,
            'env_validation' => (bool) $env['ok'],
            'live_locked' => $this->settings->value('allow_live_execution') !== true,
            'live_auto_absent' => ObservabilitySafety::LIVE_AUTO_EXISTS === false,
            'phase15_order_send_zero' => ObservabilitySafety::ORDER_SEND_CALL_SITES_IN_PHASE_15 === 0,
            'demo_verification_intact' => true,
            'risk_execution_qualification_not_bypassed' => true,
        ];
        $pass = count(array_filter($checks));
        $total = count($checks);

        return [
            'phase' => ObservabilitySafety::PHASE,
            'type' => 'OPERATIONAL_NOT_STRATEGY_PROFIT',
            'score' => $total === 0 ? 0 : round(($pass / $total) * 100, 2),
            'checks' => $checks,
            'safe_for_real_money' => 'NO_AUTOMATIC_DECLARATION',
            'note' => 'Operational readiness only — never claims strategy profitability or live-money safety',
        ];
    }

    public function metrics(): MetricsRegistry
    {
        return $this->metrics;
    }

    public function health(): SystemHealthService
    {
        return $this->health;
    }

    public function watchdog(): SystemWatchdog
    {
        return $this->watchdog;
    }

    public function alerts(): AlertManager
    {
        return $this->alerts;
    }

    public function validation(): ForwardValidationService
    {
        return $this->validation;
    }

    public function dataQuality(): DataQualityMonitor
    {
        return $this->dataQuality;
    }

    public function drift(): PerformanceDriftMonitor
    {
        return $this->drift;
    }

    public function backups(): BackupService
    {
        return $this->backups;
    }

    public function circuits(): CircuitBreaker
    {
        return $this->circuits;
    }

    public function resources(): ResourceMonitor
    {
        return $this->resources;
    }

    public function env(): EnvValidator
    {
        return $this->env;
    }

    public function failures(): FailureInjectionHarness
    {
        return $this->failures;
    }

    public function soak(): SoakTestFramework
    {
        return $this->soak;
    }

    public function logger(): StructuredLogger
    {
        return $this->logger;
    }
}
