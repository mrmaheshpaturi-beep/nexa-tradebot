<?php

namespace App\Intelligence\Support;

/**
 * Hard safety constants for Phase 13 — advisory/shadow only.
 * AI must never gain a path to MT5 / order_send / risk / settings / strategy mutation.
 */
final class IntelligenceSafety
{
    public const ENGINE_VERSION = 'TradeIntelligence/v1';

    public const PROMPT_VERSION = 'intel-prompt/v1';

    public const LIVE_EXECUTION = false;

    public const ORDER_SEND = false;

    public const MUTATION_TOOLS = false;

    public const AUTO_PROMOTE = false;

    public const HARD_BLOCKED_LIVE = 'HARD_BLOCKED';

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
        'promote_strategy',
        'promote_risk',
        'enable_live',
        'DemoBridgeClient',
        'TradingBridgeDemoClient',
    ];

    /**
     * Injection / mutation intent patterns blocked in chat & prompts.
     *
     * @var list<string>
     */
    public const INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/you\s+are\s+now\s+(an?\s+)?(admin|root|executor)/i',
        '/call\s+(tool|function)\s*[:=]/s*order_send/i',
        '/\border_send\s*\(/i',
        '/enable\s+live\s+(trading|execution)/i',
        '/mutate\s+(risk|settings|strategy)/i',
        '/promote\s+(strategy|risk)/i',
        '/execute\s+(trade|order|position)/i',
        '/system:\s*override/i',
    ];

    public static function safetyFlags(): array
    {
        return [
            'live_execution' => self::LIVE_EXECUTION,
            'order_send' => self::ORDER_SEND,
            'mutation_tools_available' => self::MUTATION_TOOLS,
            'auto_promote' => self::AUTO_PROMOTE,
            'live_status' => self::HARD_BLOCKED_LIVE,
            'modes_allowed' => ['ADVISORY', 'SHADOW'],
            'forbidden_tools' => self::FORBIDDEN_TOOL_NAMES,
            'engine_version' => self::ENGINE_VERSION,
        ];
    }

    public static function detectInjection(string $text): bool
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
