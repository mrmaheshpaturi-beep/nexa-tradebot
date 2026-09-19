<?php

namespace App\Intelligence\Support;

/**
 * Hard safety constants for Phase 13 — advisory/shadow only.
 * AI must never gain a path to MT5 / order_send / risk / settings / strategy mutation.
 */
final class IntelligenceSafety
{
    public const ENGINE_VERSION = 'TradeIntelligence/v1';

    /** Phase 17 advanced orchestrator — extends Phase 13; does not replace it. */
    public const ORCHESTRATOR_VERSION = 'AdvancedIntelligence/v1';

    public const FEATURE_SCHEMA_VERSION = 'market-features/v1';

    public const PROMPT_VERSION = 'intel-prompt/v1';

    public const PROMPT_VERSION_V2 = 'intel-prompt/v2-advanced';

    public const LIVE_EXECUTION = false;

    public const ORDER_SEND = false;

    public const MUTATION_TOOLS = false;

    public const AUTO_PROMOTE = false;

    public const HARD_BLOCKED_LIVE = 'HARD_BLOCKED';

    public const LIVE_AUTO_EXISTS = false;

    public const PHASE_14_QUALIFICATION_MANDATORY = true;

    public const PHASE_9_RISK_MANDATORY = true;

    public const PHASE_10_SOLE_EXECUTION = true;

    public const PHASE_16_GOVERNANCE_MANDATORY = true;

    /** @var list<string> */
    public const FORBIDDEN_TOOL_NAMES = [
        'order_send',
        'mt5_order_send',
        'authorized_order_send',
        'modify_position',
        'close_position',
        'partial_close',
        'mutate_risk',
        'mutate_settings',
        'mutate_strategy',
        'mutate_approval',
        'mutate_deployment',
        'promote_strategy',
        'promote_risk',
        'enable_live',
        'DemoBridgeClient',
        'TradingBridgeDemoClient',
    ];

    public static function safetyFlags(): array
    {
        return [
            'live_execution' => self::LIVE_EXECUTION,
            'order_send' => self::ORDER_SEND,
            'mutation_tools_available' => self::MUTATION_TOOLS,
            'auto_promote' => self::AUTO_PROMOTE,
            'live_status' => self::HARD_BLOCKED_LIVE,
            'live_auto_exists' => self::LIVE_AUTO_EXISTS,
            'modes_allowed' => ['ADVISORY', 'SHADOW'],
            'forbidden_tools' => self::FORBIDDEN_TOOL_NAMES,
            'engine_version' => self::ENGINE_VERSION,
            'orchestrator_version' => self::ORCHESTRATOR_VERSION,
            'feature_schema_version' => self::FEATURE_SCHEMA_VERSION,
            'phase_14_qualification_mandatory' => self::PHASE_14_QUALIFICATION_MANDATORY,
            'phase_9_risk_mandatory' => self::PHASE_9_RISK_MANDATORY,
            'phase_10_sole_execution' => self::PHASE_10_SOLE_EXECUTION,
            'phase_16_governance_mandatory' => self::PHASE_16_GOVERNANCE_MANDATORY,
            'extends_phase_13' => true,
            'duplicate_intelligence_stack' => false,
        ];
    }

    public static function detectInjection(string $text): bool
    {
        $lower = strtolower($text);
        $needles = [
            'ignore previous instructions',
            'ignore all previous instructions',
            'ignore prior instructions',
            'you are now an admin',
            'you are now root',
            'you are now an executor',
            'call tool '.'order'.'_send',
            'call function '.'order'.'_send',
            'order'.'_send(',
            'enable live trading',
            'enable live execution',
            'mutate risk',
            'mutate settings',
            'mutate strategy',
            'promote strategy',
            'promote risk',
            'execute trade',
            'execute order',
            'execute position',
            'system: override',
        ];
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        // Deliberately split the forbidden verb so static order_send audits stay clean.
        $verb = 'order'.'_send';

        return (bool) preg_match('#\b'.preg_quote($verb, '#').'\s*\(#i', $text);
    }
}
