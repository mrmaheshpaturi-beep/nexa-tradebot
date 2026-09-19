<?php

namespace App\Governance\Support;

/**
 * Hard safety constants for Phase 16 Strategy Governance.
 * AI must NEVER approve, deploy, or change active config.
 * Governance / Lab never call order_send. LIVE_AUTO does not exist.
 */
final class GovernanceSafety
{
    public const PHASE = 16;

    public const ORDER_SEND_CALL_SITES_IN_PHASE_16 = 0;

    public const PHASE_10_ORDER_SEND = 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send';

    public const AI_MAY_APPROVE = false;

    public const AI_MAY_DEPLOY = false;

    public const AI_MAY_CHANGE_ACTIVE_CONFIG = false;

    public const LIVE_AUTO_EXISTS = false;

    public const LIVE_DEPLOY_EXISTS = false;

    /** @var list<string> */
    public const ALLOWED_DEPLOY_TARGETS = ['DEMO_AUTO'];

    /** @var list<string> */
    public const FORBIDDEN_DEPLOY_TARGETS = ['LIVE', 'LIVE_AUTO', 'UNKNOWN', 'PRODUCTION', 'REAL'];

    /** @var list<string> */
    public const EVIDENCE_LABELS = ['DEMO', 'BACKTEST', 'WALK_FORWARD', 'DRY_RUN'];

    public const DEFAULT_APPROVAL_TTL_SECONDS = 900;

    public const DEFAULT_LAB_TIMEOUT_SECONDS = 30;

    public const MIN_SAMPLES_FOR_AUTO_PASS = 30; // still cannot auto-approve deploy

    public const POSITIONS_PRESERVED_ON_ROLLBACK = true;

    public const HISTORY_PRESERVED_ON_RETIRE = true;

    public static function assertNotAiActor(?string $actorType): void
    {
        $t = strtoupper((string) $actorType);
        if (in_array($t, ['AI', 'AGENT', 'AUTONOMOUS', 'LLM', 'INTELLIGENCE'], true)) {
            throw new \RuntimeException('AI cannot approve, deploy, or change active governance config.');
        }
    }

    public static function assertDeployTargetAllowed(string $target): void
    {
        $t = strtoupper(trim($target));
        if (in_array($t, self::FORBIDDEN_DEPLOY_TARGETS, true) || ! in_array($t, self::ALLOWED_DEPLOY_TARGETS, true)) {
            throw new \InvalidArgumentException("Deploy target {$target} forbidden. DEMO_AUTO only. LIVE/LIVE_AUTO do not exist.");
        }
    }

    public static function assertEvidenceLabel(string $label): void
    {
        if (! in_array(strtoupper($label), self::EVIDENCE_LABELS, true)) {
            throw new \InvalidArgumentException('Evidence label must be DEMO|BACKTEST|WALK_FORWARD|DRY_RUN — never mixed.');
        }
    }

    public static function assertNoLiveAuto(): void
    {
        if (self::LIVE_AUTO_EXISTS) {
            throw new \LogicException('LIVE_AUTO must not exist.');
        }
    }
}
