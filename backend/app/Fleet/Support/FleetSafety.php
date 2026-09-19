<?php

namespace App\Fleet\Support;

/**
 * Phase 18 non-negotiable safety constants.
 * Fleet routing never duplicates Phase 10 execution or invents order_send.
 */
final class FleetSafety
{
    public const PHASE = 18;

    public const FLEET_VERSION = 'BrokerFleet/v1';

    public const EXECUTION_AUTHORITY = 'PHASE_10_EXECUTION_ENGINE';

    public const ORDER_SEND_LOCATION = 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send';

    public const LIVE_AUTO_EXISTS = false;

    public const COPY_TRADING_EXISTS = false;

    public const AI_MAY_ROUTE = false;

    public const AI_MAY_ALLOCATE = false;

    public const AI_MAY_CHANGE_RISK = false;

    /** @return array<string, mixed> */
    public static function matrix(): array
    {
        return [
            'phase' => self::PHASE,
            'fleet_version' => self::FLEET_VERSION,
            'execution_authority' => self::EXECUTION_AUTHORITY,
            'order_send_location' => self::ORDER_SEND_LOCATION,
            'new_order_send_paths' => 0,
            'live_auto_exists' => self::LIVE_AUTO_EXISTS,
            'copy_trading' => self::COPY_TRADING_EXISTS,
            'ai_may_route' => self::AI_MAY_ROUTE,
            'ai_may_allocate' => self::AI_MAY_ALLOCATE,
            'ai_may_change_risk' => self::AI_MAY_CHANGE_RISK,
            'live_unknown' => 'HARD_BLOCKED',
            'independent_account_environment_verification' => true,
            'phase_10_sole_execution' => true,
            'phase_9_risk_mandatory' => true,
            'phase_11_account_bound' => true,
            'phase_14_account_scoped' => true,
            'phase_16_approved_assignments_only' => true,
        ];
    }

    public static function refuseAiMutation(string $action = 'route'): never
    {
        throw new \RuntimeException("AI cannot {$action} in Phase 18 fleet architecture.");
    }
}
