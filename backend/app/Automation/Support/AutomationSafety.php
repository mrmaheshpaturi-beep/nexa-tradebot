<?php

namespace App\Automation\Support;

/**
 * Hard safety constants for Phase 14. LIVE_AUTO does not exist.
 * Phase 14 never contains an order_send call site.
 */
final class AutomationSafety
{
    public const ENGINE_VERSION = 'AutomatedTradingOrchestrator/v1';

    public const ORDER_SEND_CALL_SITES_IN_PHASE_14 = 0;

    public const PHASE_10_ORDER_SEND = 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send';

    /** @var list<string> */
    public const FORBIDDEN_MODES = ['LIVE_AUTO', 'LIVE', 'REAL', 'AUTO_LIVE', 'REAL_AUTO'];

    /** @var list<string> */
    public const ALLOWED_MODES = ['OFF', 'DRY_RUN', 'DEMO_AUTO'];

    public const UI_LABEL_DEMO = 'AUTO DEMO';

    public const UI_LABEL_LIVE_FORBIDDEN = 'AUTO LIVE — NOT AVAILABLE';

    public static function assertModeAllowed(string $mode): void
    {
        $normalized = strtoupper(trim($mode));
        if (in_array($normalized, self::FORBIDDEN_MODES, true)) {
            throw new \InvalidArgumentException('LIVE_AUTO / LIVE automation modes do not exist and are hard-rejected.');
        }
        if (! in_array($normalized, self::ALLOWED_MODES, true)) {
            throw new \InvalidArgumentException("Unsupported automation mode: {$mode}");
        }
    }

    public static function isLiveLike(string $tradeMode): bool
    {
        $m = strtoupper(trim($tradeMode));

        return in_array($m, ['LIVE', 'REAL', 'UNKNOWN', 'AMBIGUOUS', 'UNVERIFIED', 'CONTEST', ''], true);
    }
}
