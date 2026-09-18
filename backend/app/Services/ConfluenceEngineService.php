<?php

namespace App\Services;

/**
 * Merges multi-strategy evidence without double-counting the same family.
 * Conflicts reduce score. Output score is 0–100 confluence — NOT win probability.
 */
class ConfluenceEngineService
{
    /**
     * @param  list<array<string, mixed>>  $evaluations  StrategyEvaluation::toArray() rows that are SIGNAL
     * @return array<string, mixed>
     */
    public function merge(array $evaluations, string $preferredDirection = ''): array
    {
        if ($evaluations === []) {
            return [
                'direction' => 'NEUTRAL',
                'score' => 0.0,
                'families' => [],
                'evidence' => [],
                'conflicts' => [],
                'breakdown' => ['plugins' => 0],
                'disclaimer' => 'Score is a transparent confluence measure (0–100), not a win probability or guarantee.',
            ];
        }

        $buy = [];
        $sell = [];
        foreach ($evaluations as $row) {
            if (($row['direction'] ?? '') === 'BUY') {
                $buy[] = $row;
            } elseif (($row['direction'] ?? '') === 'SELL') {
                $sell[] = $row;
            }
        }

        $direction = count($buy) >= count($sell) ? 'BUY' : 'SELL';
        if ($preferredDirection !== '' && in_array($preferredDirection, ['BUY', 'SELL'], true)) {
            $direction = $preferredDirection;
        }
        if (count($buy) === count($sell) && $preferredDirection === '') {
            $avgBuy = $this->avgScore($buy);
            $avgSell = $this->avgScore($sell);
            $direction = $avgBuy >= $avgSell ? 'BUY' : 'SELL';
        }

        $supporting = $direction === 'BUY' ? $buy : $sell;
        $opposing = $direction === 'BUY' ? $sell : $buy;

        $families = [];
        $evidence = [];
        $score = 0.0;
        foreach ($supporting as $row) {
            foreach ($row['evidence'] ?? [] as $ev) {
                $family = (string) ($ev['family'] ?? 'OTHER');
                if (isset($families[$family])) {
                    // Avoid double-counting: keep stronger weight only
                    if (($ev['weight'] ?? 0) <= $families[$family]['weight']) {
                        continue;
                    }
                    $score -= $families[$family]['contribution'];
                }
                $contribution = min(18.0, (float) ($row['raw_score'] ?? 0) * 0.18 * (float) ($ev['weight'] ?? 1));
                $families[$family] = [
                    'family' => $family,
                    'plugin' => $row['plugin_key'] ?? null,
                    'weight' => (float) ($ev['weight'] ?? 1),
                    'contribution' => $contribution,
                    'detail' => $ev['detail'] ?? '',
                ];
                $score += $contribution;
                $evidence[] = $ev;
            }
            // Plugin diversity bonus (capped)
            $score += 4.0;
        }

        $conflicts = [];
        foreach ($opposing as $row) {
            $penalty = min(12.0, (float) ($row['raw_score'] ?? 0) * 0.1);
            $score -= $penalty;
            $conflicts[] = [
                'plugin' => $row['plugin_key'] ?? null,
                'direction' => $row['direction'] ?? null,
                'penalty' => $penalty,
                'reason' => $row['reason'] ?? 'OPPOSING_SIGNAL',
            ];
        }

        $familyCount = count($families);
        if ($familyCount >= 3) {
            $score += 8;
        } elseif ($familyCount === 2) {
            $score += 4;
        }

        $score = max(0, min(100, $score));

        return [
            'direction' => $direction,
            'score' => round($score, 3),
            'families' => array_values($families),
            'evidence' => $evidence,
            'conflicts' => $conflicts,
            'supporting_plugins' => array_values(array_map(fn ($r) => $r['plugin_key'] ?? null, $supporting)),
            'opposing_plugins' => array_values(array_map(fn ($r) => $r['plugin_key'] ?? null, $opposing)),
            'breakdown' => [
                'supporting_count' => count($supporting),
                'opposing_count' => count($opposing),
                'family_count' => $familyCount,
                'raw_before_cap' => $score,
            ],
            'disclaimer' => 'Score is a transparent confluence measure (0–100), not a win probability or guarantee.',
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function avgScore(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        return array_sum(array_map(fn ($r) => (float) ($r['raw_score'] ?? 0), $rows)) / count($rows);
    }
}
