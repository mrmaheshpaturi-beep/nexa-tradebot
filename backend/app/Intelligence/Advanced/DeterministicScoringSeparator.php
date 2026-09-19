<?php

namespace App\Intelligence\Advanced;

/**
 * Separates deterministic scoring from AI assessment payloads.
 * AI never feeds back into deterministic rank scores.
 */
class DeterministicScoringSeparator
{
    /**
     * @param  array<string, mixed>  $deterministic
     * @param  array<string, mixed>|null  $aiAssessment
     * @return array<string, mixed>
     */
    public function separate(array $deterministic, ?array $aiAssessment = null): array
    {
        $score = (float) ($deterministic['rank_score']
            ?? $deterministic['opportunity']['rank_score']
            ?? $deterministic['confluence_score']
            ?? 0);

        return [
            'deterministic' => [
                'rank_score' => $score,
                'sources' => $deterministic['sources'] ?? [
                    'ensemble',
                    'market_quality',
                    'mtf',
                    'features',
                    'analogs',
                ],
                'ai_influences_score' => false,
                'payload' => $deterministic,
            ],
            'ai_assessment' => [
                'present' => $aiAssessment !== null,
                'influences_deterministic_score' => false,
                'advisory_only' => true,
                'mutation_tools' => false,
                'payload' => $aiAssessment,
            ],
            'separation_enforced' => true,
            'disclaimer' => 'Deterministic scores are independent of AI narrative output.',
        ];
    }
}
