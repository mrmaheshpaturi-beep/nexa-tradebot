<?php

namespace App\Services;

use App\Models\TradingStrategy;
use App\Technical\TechnicalSnapshot;

/**
 * Pre-evaluation gates. Fail closed. Never executes trades.
 */
class StrategyGateService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MarketSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $marketSnapshot
     * @return array{allowed:bool,reason:?string,checks:array<string,mixed>}
     */
    public function evaluate(
        TradingStrategy $strategy,
        string $symbol,
        string $timeframe,
        array $marketSnapshot,
        TechnicalSnapshot $technical,
    ): array {
        $checks = [];

        if (! $strategy->enabled || $strategy->status !== 'ACTIVE') {
            return $this->deny('STRATEGY_DISABLED', $checks);
        }
        if ($strategy->auto_trading_enabled) {
            return $this->deny('AUTO_TRADING_MUST_BE_DISABLED', $checks);
        }

        $symbols = array_map('strtoupper', $strategy->symbols ?? []);
        $checks['symbol_eligible'] = in_array(strtoupper($symbol), $symbols, true);
        if (! $checks['symbol_eligible']) {
            return $this->deny('SYMBOL_NOT_ASSIGNED', $checks);
        }

        $tfs = array_map('strtoupper', $strategy->timeframes ?? []);
        $checks['timeframe_eligible'] = in_array(strtoupper($timeframe), $tfs, true);
        if (! $checks['timeframe_eligible']) {
            return $this->deny('TIMEFRAME_NOT_ASSIGNED', $checks);
        }

        $quality = $marketSnapshot['data_quality'] ?? $technical->quality;
        $gate = $marketSnapshot['data_quality_gate'] ?? $technical->gate;
        $checks['data_quality'] = $quality['status'] ?? 'UNKNOWN';
        $checks['analysis_gate'] = $gate;
        if (! ($gate['allowed'] ?? false) || in_array($quality['status'] ?? '', ['BAD', 'UNAVAILABLE'], true)) {
            return $this->deny('DATA_QUALITY_GATE', $checks);
        }
        if ($technical->status === 'REFUSED') {
            return $this->deny('TECHNICAL_REFUSED', $checks);
        }

        $freshness = $quality['freshness_seconds'] ?? $quality['max_age_seconds'] ?? null;
        $checks['freshness_seconds'] = $freshness;
        if (is_numeric($freshness) && (float) $freshness > 900) {
            return $this->deny('DATA_STALE', $checks);
        }

        $allowedSessions = $strategy->sessions ?? [];
        if (is_array($allowedSessions) && $allowedSessions !== []) {
            $active = $this->sessions->sessions()['active'] ?? [];
            $normalizedActive = array_map(function ($name) {
                $name = strtoupper((string) $name);
                return match ($name) {
                    'TOKYO', 'SYDNEY' => 'ASIA',
                    'LONDON' => 'LONDON',
                    'NEW YORK', 'NEW_YORK' => 'NEW_YORK',
                    default => $name,
                };
            }, $active);
            $allowed = array_map('strtoupper', $allowedSessions);
            $sessionOk = count(array_intersect($normalizedActive, $allowed)) > 0;
            $checks['session_active'] = $active;
            $checks['allowed_sessions'] = $allowedSessions;
            if (! $sessionOk) {
                return $this->deny('SESSION_FILTER', $checks);
            }
        }

        $spread = null;
        $quotes = $marketSnapshot['quotes'] ?? [];
        foreach ($quotes as $q) {
            if (strtoupper((string) ($q['symbol'] ?? '')) === strtoupper($symbol)) {
                $spread = $q['spread'] ?? $q['spread_points'] ?? null;
                break;
            }
        }
        $checks['spread'] = $spread;
        $maxSpread = $strategy->parameters['max_spread'] ?? null;
        if ($maxSpread !== null && $spread !== null && (float) $spread > (float) $maxSpread) {
            return $this->deny('SPREAD_TOO_WIDE', $checks);
        }

        if ($this->settings->value('emergency_stop') === true) {
            return $this->deny('EMERGENCY_STOP', $checks);
        }

        $checks['volatility_regime'] = $technical->structure['bias'] ?? null;
        $checks['evaluation_mode'] = $strategy->evaluation_mode ?? 'ON_CANDLE_CLOSE';

        return ['allowed' => true, 'reason' => null, 'checks' => $checks];
    }

    /** @param  array<string, mixed>  $checks */
    private function deny(string $reason, array $checks): array
    {
        return ['allowed' => false, 'reason' => $reason, 'checks' => $checks];
    }
}
