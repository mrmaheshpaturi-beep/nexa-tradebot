<?php

namespace App\Hardening\Support;

/**
 * Phase 19 non-negotiable safety constants.
 * Production hardening never introduces order_send, LIVE_AUTO, or AI mutation.
 */
final class HardeningSafety
{
    public const PHASE = 19;

    public const HARDENING_VERSION = 'ProductionHardening/v1';

    public const EXECUTION_AUTHORITY = 'PHASE_10_EXECUTION_ENGINE';

    public const ORDER_SEND_LOCATION = 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send';

    public const ORDER_SEND_CALL_SITES_IN_PHASE_19 = 0;

    public const AI_EXECUTION_CALL_SITES = 0;

    public const AI_MAY_MUTATE_RISK = false;

    public const AI_MAY_EXECUTE = false;

    public const LIVE_AUTO_EXISTS = false;

    public const UNKNOWN_EXECUTION_POLICY = 'RECONCILE_NOT_RETRY';

    public const PHASE_9_RISK_MANDATORY = true;

    public const PHASE_10_SOLE_EXECUTION = true;

    public const PHASE_16_GOVERNANCE_INTACT = true;

    public const PHASE_18_ISOLATION_INTACT = true;

    /** @var list<string> */
    public const APP_ENVIRONMENTS = ['LOCAL', 'STAGING', 'DEMO_VPS'];

    /** @var list<string> */
    public const BROKER_TRADE_MODES = ['SIMULATION', 'DEMO'];

    /** @var list<string> */
    public const FORBIDDEN_BROKER_MODES = ['LIVE', 'UNKNOWN', 'LIVE_AUTO'];

    /** @var list<string> */
    public const FORBIDDEN_APP_ENVIRONMENTS = ['LIVE_PRODUCTION', 'LIVE', 'REAL_PRODUCTION', 'PRODUCTION_LIVE'];

    /** @var list<string> */
    public const SCOPED_SAFE_MODES = [
        'GLOBAL',
        'PORTFOLIO',
        'ACCOUNT',
        'AUTOMATION',
        'DEPLOY_MAINTENANCE',
    ];

    /** @var list<string> */
    public const SOAK_ALLOWED_ENVIRONMENTS = ['LOCAL', 'STAGING', 'DEMO_VPS', 'TEST', 'DEMO'];

    /** @return array<string, mixed> */
    public static function matrix(): array
    {
        return [
            'phase' => self::PHASE,
            'hardening_version' => self::HARDENING_VERSION,
            'execution_authority' => self::EXECUTION_AUTHORITY,
            'order_send_location' => self::ORDER_SEND_LOCATION,
            'order_send_phase19' => self::ORDER_SEND_CALL_SITES_IN_PHASE_19,
            'ai_execution' => self::AI_EXECUTION_CALL_SITES,
            'ai_may_mutate_risk' => self::AI_MAY_MUTATE_RISK,
            'ai_may_execute' => self::AI_MAY_EXECUTE,
            'live_auto_exists' => self::LIVE_AUTO_EXISTS,
            'unknown_execution_policy' => self::UNKNOWN_EXECUTION_POLICY,
            'blind_retry_on_unknown' => 'NONE',
            'phase_9_risk_mandatory' => self::PHASE_9_RISK_MANDATORY,
            'phase_10_sole_execution' => self::PHASE_10_SOLE_EXECUTION,
            'phase_16_governance_intact' => self::PHASE_16_GOVERNANCE_INTACT,
            'phase_18_isolation_intact' => self::PHASE_18_ISOLATION_INTACT,
            'live_unknown' => 'HARD_BLOCKED',
            'app_environments' => self::APP_ENVIRONMENTS,
            'broker_trade_modes' => self::BROKER_TRADE_MODES,
            'forbidden_broker_modes' => self::FORBIDDEN_BROKER_MODES,
            'soak_against_live' => 'FORBIDDEN',
        ];
    }

    public static function assertAppEnvironment(string $environment): void
    {
        $env = strtoupper(trim($environment));
        if (in_array($env, self::FORBIDDEN_APP_ENVIRONMENTS, true) || ! in_array($env, self::APP_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(
                "App environment {$environment} is not allowed. Allowed: LOCAL|STAGING|DEMO_VPS. LIVE_PRODUCTION does not exist."
            );
        }
    }

    public static function assertBrokerTradeMode(string $mode): void
    {
        $m = strtoupper(trim($mode));
        if (in_array($m, self::FORBIDDEN_BROKER_MODES, true) || ! in_array($m, self::BROKER_TRADE_MODES, true)) {
            throw new \InvalidArgumentException(
                "Broker trade mode {$mode} is hard-blocked. Allowed: SIMULATION|DEMO. LIVE/UNKNOWN/LIVE_AUTO forbidden."
            );
        }
    }

    public static function refuseAiMutation(string $action = 'mutate'): never
    {
        throw new \RuntimeException("AI cannot {$action} in Phase 19 production hardening.");
    }

    public static function refuseLiveAuto(): never
    {
        throw new \RuntimeException('LIVE_AUTO does not exist in Phase 19.');
    }
}
