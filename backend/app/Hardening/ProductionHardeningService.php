<?php

namespace App\Hardening;

use App\Hardening\Deploy\TradingAwareDeployService;
use App\Hardening\Dr\DisasterRecoveryHardening;
use App\Hardening\Identity\IdentityHardeningService;
use App\Hardening\Queues\HardeningJobQueue;
use App\Hardening\Secrets\SecretInventoryService;
use App\Hardening\Security\SecurityDefenseService;
use App\Hardening\Support\HardeningSafety;
use App\Hardening\Workers\WorkerSupervisor;
use App\Observability\AlertManager;
use App\Observability\StructuredLogger;

/**
 * Phase 19 Production Hardening facade — extends Phase 15/18; does not rewrite engines.
 */
class ProductionHardeningService
{
    public function __construct(
        private readonly AppBrokerEnvironmentService $envs,
        private readonly SecretInventoryService $secrets,
        private readonly IdentityHardeningService $identity,
        private readonly SecurityDefenseService $security,
        private readonly HardeningJobQueue $queues,
        private readonly WorkerSupervisor $workers,
        private readonly DisasterRecoveryHardening $dr,
        private readonly TradingAwareDeployService $deploy,
        private readonly OpsControlCenterService $ops,
        private readonly PerformanceCapacityService $capacity,
        private readonly SoakChaosHarness $soak,
        private readonly DependencyAuditService $deps,
        private readonly AlertManager $alerts,
        private readonly StructuredLogger $logger,
    ) {}

    public function envs(): AppBrokerEnvironmentService
    {
        return $this->envs;
    }

    public function secrets(): SecretInventoryService
    {
        return $this->secrets;
    }

    public function identity(): IdentityHardeningService
    {
        return $this->identity;
    }

    public function security(): SecurityDefenseService
    {
        return $this->security;
    }

    public function queues(): HardeningJobQueue
    {
        return $this->queues;
    }

    public function workers(): WorkerSupervisor
    {
        return $this->workers;
    }

    public function dr(): DisasterRecoveryHardening
    {
        return $this->dr;
    }

    public function deploy(): TradingAwareDeployService
    {
        return $this->deploy;
    }

    public function ops(): OpsControlCenterService
    {
        return $this->ops;
    }

    public function capacity(): PerformanceCapacityService
    {
        return $this->capacity;
    }

    public function soak(): SoakChaosHarness
    {
        return $this->soak;
    }

    public function deps(): DependencyAuditService
    {
        return $this->deps;
    }

    public function alerts(): AlertManager
    {
        return $this->alerts;
    }

    public function logger(): StructuredLogger
    {
        return $this->logger;
    }

    /** @return array<string, mixed> */
    public function matrix(): array
    {
        return HardeningSafety::matrix();
    }

    /** @return array<string, mixed> */
    public function statusPayload(): array
    {
        return [
            'phase' => HardeningSafety::PHASE,
            'status' => 'READY',
            'hardening_version' => HardeningSafety::HARDENING_VERSION,
            'order_send_phase19' => 0,
            'ai_execution' => 0,
            'live_auto_exists' => false,
            'unknown_execution_policy' => HardeningSafety::UNKNOWN_EXECUTION_POLICY,
            'phase_9_risk_mandatory' => true,
            'phase_10_sole_execution' => true,
            'phase_16_governance_intact' => true,
            'phase_18_isolation_intact' => true,
            'ops_control_center_api' => '/api/v1/hardening/ops',
            'trading_readiness_api' => '/api/v1/health/trading-readiness',
            'windows_mt5' => 'PENDING_MANUAL_VALIDATION',
            'soak_multi_day' => 'PENDING',
            'vps_restore_drill' => 'PENDING_MANUAL',
        ];
    }
}
