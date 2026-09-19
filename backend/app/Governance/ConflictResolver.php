<?php

namespace App\Governance;

/**
 * Deterministic conflict resolution for versioned strategy portfolios.
 * Same inputs always produce the same member ordering and winner.
 */
class ConflictResolver
{
    /**
     * @param  list<array<string,mixed>>  $members
     * @return array{members: list<array<string,mixed>>, resolution: array<string,mixed>}
     */
    public function resolve(array $members): array
    {
        $normalized = [];
        foreach ($members as $m) {
            $key = strtolower((string) ($m['strategy_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $normalized[] = [
                'strategy_key' => $key,
                'semantic_version' => (string) ($m['semantic_version'] ?? '0.0.0'),
                'weight' => (float) ($m['weight'] ?? 1.0),
                'priority' => (int) ($m['priority'] ?? 100),
            ];
        }

        // Deterministic sort: priority ASC, weight DESC, strategy_key ASC, semantic_version DESC
        usort($normalized, function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority']
                ?: $b['weight'] <=> $a['weight']
                ?: strcmp($a['strategy_key'], $b['strategy_key'])
                ?: -version_compare($a['semantic_version'], $b['semantic_version']);
        });

        // Same strategy_key → keep highest semantic_version (first after sort by version desc within key)
        $byKey = [];
        $conflicts = [];
        foreach ($normalized as $row) {
            $k = $row['strategy_key'];
            if (! isset($byKey[$k])) {
                $byKey[$k] = $row;
            } else {
                $conflicts[] = [
                    'strategy_key' => $k,
                    'kept' => $byKey[$k]['semantic_version'],
                    'dropped' => $row['semantic_version'],
                    'rule' => 'HIGHEST_SEMVER_THEN_PRIORITY_THEN_WEIGHT',
                ];
                if (version_compare($row['semantic_version'], $byKey[$k]['semantic_version']) > 0) {
                    $byKey[$k] = $row;
                }
            }
        }

        $resolved = array_values($byKey);
        usort($resolved, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']
            ?: strcmp($a['strategy_key'], $b['strategy_key']));

        return [
            'members' => $resolved,
            'resolution' => [
                'algorithm' => 'PRIORITY_ASC_WEIGHT_DESC_KEY_ASC_SEMVER_DESC',
                'deterministic' => true,
                'conflicts' => $conflicts,
            ],
        ];
    }
}
