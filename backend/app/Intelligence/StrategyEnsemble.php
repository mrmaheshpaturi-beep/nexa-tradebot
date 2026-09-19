<?php

namespace App\Intelligence;

use App\Services\ConfluenceEngineService;

/**
 * Strategy ensemble with evidence-family diversity + conflict representation.
 * Builds on Phase 7 ConfluenceEngine concepts — advisory only.
 */
class StrategyEnsemble
{
    public function __construct(
        private readonly ConfluenceEngineService $confluence = new ConfluenceEngineService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $pluginEvaluations  StrategyEvaluation-like rows
     * @return array<string, mixed>
     */
    public function build(array $pluginEvaluations, string $preferredDirection = ''): array
    {
        $merged = $this->confluence->merge($pluginEvaluations, $preferredDirection);
        $families = $merged['families'] ?? [];
        $familyKeys = [];
        if (is_array($families)) {
            foreach ($families as $f) {
                if (is_array($f) && isset($f['family'])) {
                    $familyKeys[] = (string) $f['family'];
                } elseif (is_string($f)) {
                    $familyKeys[] = $f;
                }
            }
        }
        $diversity = count(array_unique($familyKeys));

        return [
            'direction' => $merged['direction'] ?? 'NEUTRAL',
            'confluence_score' => (float) ($merged['score'] ?? 0),
            'families' => $families,
            'evidence' => $merged['evidence'] ?? [],
            'conflicts' => $merged['conflicts'] ?? [],
            'family_diversity' => $diversity,
            'diversity_bonus' => min(12.0, $diversity * 3.0),
            'breakdown' => $merged['breakdown'] ?? [],
            'disclaimer' => $merged['disclaimer'] ?? 'Ensemble confluence is advisory (0–100), not win probability.',
            'execution_authority' => false,
        ];
    }
}
