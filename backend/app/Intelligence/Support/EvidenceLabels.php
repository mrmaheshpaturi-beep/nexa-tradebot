<?php

namespace App\Intelligence\Support;

/**
 * Evidence sources must remain STRICTLY distinct — never mix labels in one bucket.
 */
final class EvidenceLabels
{
    public const HISTORICAL = 'HISTORICAL';

    public const DEMO = 'DEMO';

    public const BACKTEST = 'BACKTEST';

    public const OOS = 'OOS';

    public const EXECUTION = 'EXECUTION';

    public const PORTFOLIO = 'PORTFOLIO';

    public const ALL = [
        self::HISTORICAL,
        self::DEMO,
        self::BACKTEST,
        self::OOS,
        self::EXECUTION,
        self::PORTFOLIO,
    ];

    /**
     * @param  array<string, list<mixed>>  $buckets
     * @return array{ok: bool, error: ?string, buckets: array<string, list<mixed>>}
     */
    public static function separate(array $buckets): array
    {
        $normalized = [];
        foreach (self::ALL as $label) {
            $normalized[$label] = [];
        }

        foreach ($buckets as $label => $items) {
            $key = strtoupper((string) $label);
            if (! in_array($key, self::ALL, true)) {
                return [
                    'ok' => false,
                    'error' => "UNKNOWN_EVIDENCE_LABEL:{$key}",
                    'buckets' => $normalized,
                ];
            }
            if (! is_array($items)) {
                return [
                    'ok' => false,
                    'error' => "INVALID_BUCKET:{$key}",
                    'buckets' => $normalized,
                ];
            }
            $normalized[$key] = array_values($items);
        }

        // Detect accidental cross-label pollution via shared ids
        $seen = [];
        foreach ($normalized as $label => $items) {
            foreach ($items as $item) {
                $id = is_array($item) ? (string) ($item['id'] ?? json_encode($item)) : (string) $item;
                if ($id === '') {
                    continue;
                }
                if (isset($seen[$id]) && $seen[$id] !== $label) {
                    return [
                        'ok' => false,
                        'error' => "MIXED_LABEL_REFUSED:{$id}:{$seen[$id]}+{$label}",
                        'buckets' => array_fill_keys(self::ALL, []),
                    ];
                }
                $seen[$id] = $label;
            }
        }

        return ['ok' => true, 'error' => null, 'buckets' => $normalized];
    }
}
