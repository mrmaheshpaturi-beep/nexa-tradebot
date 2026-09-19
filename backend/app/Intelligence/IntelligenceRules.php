<?php

namespace App\Intelligence;

use App\Intelligence\Support\IntelligenceSafety;

/**
 * Versioned assessment rules — deterministic, advisory only.
 */
class IntelligenceRules
{
    public const VERSION = 'intel-rules/v1';

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{code: string, severity: string, message: string}>
     */
    public function evaluate(array $context): array
    {
        $fired = [];

        $quality = (string) ($context['market_quality']['status'] ?? 'UNKNOWN');
        if (in_array($quality, ['BAD', 'UNAVAILABLE'], true)) {
            $fired[] = [
                'code' => 'MQ_BLOCK',
                'severity' => 'HIGH',
                'message' => 'Market quality is BAD/UNAVAILABLE — opportunity downgraded to NO_TRADE advisory.',
            ];
        }

        $spreadPts = (float) ($context['spread']['points'] ?? 0);
        $spreadCap = (float) ($context['spread']['cap_points'] ?? 5);
        if ($spreadPts > $spreadCap) {
            $fired[] = [
                'code' => 'SPREAD_WIDE',
                'severity' => 'MEDIUM',
                'message' => "Spread {$spreadPts} exceeds advisory cap {$spreadCap}.",
            ];
        }

        if (($context['anomaly']['detected'] ?? false) === true) {
            $fired[] = [
                'code' => 'ANOMALY_DETECTED',
                'severity' => 'HIGH',
                'message' => (string) ($context['anomaly']['detail'] ?? 'Price/volume anomaly flagged.'),
            ];
        }

        $conflicts = $context['ensemble']['conflicts'] ?? [];
        if (is_array($conflicts) && count($conflicts) >= 2) {
            $fired[] = [
                'code' => 'ENSEMBLE_CONFLICT',
                'severity' => 'MEDIUM',
                'message' => 'Multiple evidence-family conflicts reduce advisory score.',
            ];
        }

        $calStatus = (string) ($context['calendar']['provider_status'] ?? 'OK');
        if ($calStatus === 'UNAVAILABLE') {
            $fired[] = [
                'code' => 'CALENDAR_UNAVAILABLE',
                'severity' => 'LOW',
                'message' => 'Calendar provider UNAVAILABLE — fail-closed (no fabricated events as real).',
            ];
        }

        $newsStatus = (string) ($context['news']['provider_status'] ?? 'OK');
        if ($newsStatus === 'UNAVAILABLE') {
            $fired[] = [
                'code' => 'NEWS_UNAVAILABLE',
                'severity' => 'LOW',
                'message' => 'News provider UNAVAILABLE — fail-closed (no fabricated headlines as real).',
            ];
        }

        if (($context['mode'] ?? '') === 'SHADOW') {
            $fired[] = [
                'code' => 'SHADOW_MODE',
                'severity' => 'INFO',
                'message' => 'SHADOW mode — observations only; never implies execution.',
            ];
        }

        $fired[] = [
            'code' => 'NO_EXECUTION_PATH',
            'severity' => 'INFO',
            'message' => 'Intelligence cannot call MT5/order_send/risk/settings/strategy mutation. LIVE='.IntelligenceSafety::HARD_BLOCKED_LIVE,
        ];

        return $fired;
    }
}
