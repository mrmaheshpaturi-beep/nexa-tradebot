<?php

namespace App\Intelligence;

/**
 * Transparent opportunity ranking — advisory scores with full breakdown.
 */
class OpportunityRanker
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function rank(array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $c) {
            $confluence = (float) ($c['ensemble']['confluence_score'] ?? $c['confluence_score'] ?? 0);
            $diversity = (float) ($c['ensemble']['diversity_bonus'] ?? 0);
            $quality = (string) ($c['market_quality']['status'] ?? 'GOOD');
            $qualityMult = match ($quality) {
                'GOOD' => 1.0,
                'DEGRADED' => 0.7,
                'BAD', 'UNAVAILABLE' => 0.0,
                default => 0.5,
            };
            $align = (string) ($c['mtf']['alignment'] ?? 'MIXED');
            $mtfBonus = match ($align) {
                'ALIGNED' => 8.0,
                'HTF_DOMINANT' => 4.0,
                'DIVERGENT' => -10.0,
                default => 0.0,
            };
            $conflictPenalty = min(20.0, count($c['ensemble']['conflicts'] ?? []) * 5.0);
            $spreadPenalty = ((float) ($c['spread']['points'] ?? 0) > (float) ($c['spread']['cap_points'] ?? 5)) ? 15.0 : 0.0;
            $anomalyPenalty = (($c['anomaly']['detected'] ?? false) === true) ? 25.0 : 0.0;

            $raw = ($confluence + $diversity + $mtfBonus - $conflictPenalty - $spreadPenalty - $anomalyPenalty) * $qualityMult;
            $score = max(0.0, min(100.0, $raw));

            $scored[] = array_merge($c, [
                'rank_score' => round($score, 4),
                'ranking_breakdown' => [
                    'confluence' => $confluence,
                    'diversity_bonus' => $diversity,
                    'mtf_bonus' => $mtfBonus,
                    'quality_multiplier' => $qualityMult,
                    'conflict_penalty' => $conflictPenalty,
                    'spread_penalty' => $spreadPenalty,
                    'anomaly_penalty' => $anomalyPenalty,
                    'final' => round($score, 4),
                ],
                'disclaimer' => 'ADVISORY / SHADOW ranking only — does not execute trades.',
            ]);
        }

        usort($scored, fn ($a, $b) => ($b['rank_score'] <=> $a['rank_score']) ?: strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? '')));

        foreach ($scored as $i => &$row) {
            $row['rank_position'] = $i + 1;
        }
        unset($row);

        return $scored;
    }
}
