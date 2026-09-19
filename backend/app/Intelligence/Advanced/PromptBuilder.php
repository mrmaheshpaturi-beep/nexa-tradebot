<?php

namespace App\Intelligence\Advanced;

use App\Intelligence\Support\IntelligenceSafety;

/**
 * Prompt builder with schema versioning + injection-safe serialization.
 */
class PromptBuilder
{
    public const SCHEMA = 'advanced-ai-input/v1';

    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    public function buildAnalysisInput(array $parts): array
    {
        $input = [
            'schema' => self::SCHEMA,
            'prompt_version' => IntelligenceSafety::PROMPT_VERSION_V2,
            'symbol' => (string) ($parts['symbol'] ?? 'UNKNOWN'),
            'mode' => strtoupper((string) ($parts['mode'] ?? 'ADVISORY')),
            'technical' => $parts['technical'] ?? [],
            'ensemble' => $parts['ensemble'] ?? [],
            'market_quality' => $parts['market_quality'] ?? [],
            'features' => $parts['features'] ?? [],
            'analogs' => $parts['analogs'] ?? [],
            'suitability' => $parts['suitability'] ?? [],
            'mtf_matrix' => $parts['mtf_matrix'] ?? [],
            'constraints' => [
                'mutation_tools' => false,
                'order_send' => false,
                'live_execution' => false,
                'advisory_only' => true,
            ],
        ];

        // Strip obviously injurious strings from nested string leaves
        array_walk_recursive($input, function (&$v): void {
            if (is_string($v) && IntelligenceSafety::detectInjection($v)) {
                $v = '[REDACTED_INJECTION_ATTEMPT]';
            }
        });

        return $input;
    }
}
