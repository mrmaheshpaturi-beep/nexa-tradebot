<?php

namespace App\Observability\Support;

/**
 * Hard safety constants for Phase 15.
 * Observability never sends orders. LIVE_AUTO does not exist.
 * LIVE_PRODUCTION environment is forbidden.
 */
final class ObservabilitySafety
{
    public const PHASE = 15;

    public const ORDER_SEND_CALL_SITES_IN_PHASE_15 = 0;

    public const PHASE_10_ORDER_SEND = 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send';

    public const AI_EXECUTION_CALL_SITES = 0;

    public const LIVE_AUTO_EXISTS = false;

    /** @var list<string> */
    public const ALLOWED_ENVIRONMENTS = ['LOCAL', 'STAGING', 'DEMO_VPS'];

    /** @var list<string> */
    public const FORBIDDEN_ENVIRONMENTS = ['LIVE_PRODUCTION', 'LIVE', 'REAL_PRODUCTION', 'PRODUCTION_LIVE'];

    /** @var list<string> */
    public const EVIDENCE_LABELS = ['DEMO', 'BACKTEST', 'WALK_FORWARD', 'DRY_RUN'];

    /** @var list<string> */
    public const FORBIDDEN_AUTO_ACTIONS = [
        'AUTO_OPTIMIZE_STRATEGY',
        'AUTO_CHANGE_RISK_LIMITS',
        'AI_TRADE_AUTHORITY',
        'ENABLE_LIVE',
        'CREATE_LIVE_AUTO',
        'DECLARE_SAFE_FOR_REAL_MONEY',
    ];

    public const UNKNOWN_EXECUTION_POLICY = 'RECONCILE_NOT_RETRY';

    public const STALE_HEARTBEAT_BLOCKS_NEW_ENTRIES = true;

    public const BAD_DATA_BLOCKS_NEW_TRADES = true;

    public static function assertEnvironmentAllowed(string $environment): void
    {
        $env = strtoupper(trim($environment));
        if (in_array($env, self::FORBIDDEN_ENVIRONMENTS, true) || ! in_array($env, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Environment {$environment} is not allowed. Allowed: LOCAL|STAGING|DEMO_VPS. LIVE_PRODUCTION does not exist.");
        }
    }

    public static function assertEvidenceLabel(string $label): void
    {
        if (! in_array(strtoupper($label), self::EVIDENCE_LABELS, true)) {
            throw new \InvalidArgumentException('Evidence label must be one of DEMO|BACKTEST|WALK_FORWARD|DRY_RUN — never mixed.');
        }
    }
}
