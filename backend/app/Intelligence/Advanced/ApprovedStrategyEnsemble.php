<?php

namespace App\Intelligence\Advanced;

use App\Enums\StrategyLifecycleState;
use App\Intelligence\StrategyEnsemble;
use App\Models\GovernedStrategyVersion;
use App\Models\User;

/**
 * Ensemble constrained to Phase 16 governance-approved strategy versions.
 * Extends Phase 13 StrategyEnsemble — does not replace ConfluenceEngine.
 */
class ApprovedStrategyEnsemble
{
    public function __construct(
        private readonly StrategyEnsemble $base = new StrategyEnsemble,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $pluginEvaluations
     * @return array<string, mixed>
     */
    public function build(User $user, array $pluginEvaluations, string $preferredDirection = ''): array
    {
        $approved = GovernedStrategyVersion::query()
            ->where('user_id', $user->id)
            ->whereIn('lifecycle_state', [
                StrategyLifecycleState::Approved->value,
                StrategyLifecycleState::DeployedDemo->value,
            ])
            ->get(['public_id', 'strategy_key', 'semantic_version', 'lifecycle_state', 'code_hash', 'config_hash']);

        $approvedKeys = $approved->pluck('strategy_key')->filter()->unique()->all();
        $approvedMap = [];
        foreach ($approved as $v) {
            $approvedMap[(string) $v->strategy_key] = [
                'public_id' => $v->public_id,
                'semantic_version' => $v->semantic_version,
                'lifecycle_state' => $v->lifecycle_state instanceof StrategyLifecycleState
                    ? $v->lifecycle_state->value
                    : (string) $v->lifecycle_state,
                'code_hash' => $v->code_hash,
                'config_hash' => $v->config_hash,
            ];
        }

        $filtered = [];
        $rejected = [];
        foreach ($pluginEvaluations as $ev) {
            $key = (string) ($ev['plugin_key'] ?? $ev['strategy_key'] ?? '');
            // Proxies and unlabeled intel evaluations remain allowed for advisory depth
            $isProxy = str_starts_with($key, 'intel_proxy_') || $key === '';
            if ($isProxy || $approvedKeys === [] || in_array($key, $approvedKeys, true)) {
                if (! $isProxy && isset($approvedMap[$key])) {
                    $ev['governed_version'] = $approvedMap[$key];
                    $ev['governance_approved'] = true;
                } else {
                    $ev['governance_approved'] = $isProxy;
                    $ev['governance_note'] = $isProxy ? 'INTEL_PROXY' : 'NO_APPROVED_VERSIONS';
                }
                $filtered[] = $ev;
            } else {
                $rejected[] = [
                    'plugin_key' => $key,
                    'reason' => 'NOT_GOVERNANCE_APPROVED',
                ];
            }
        }

        // If caller provided only non-approved strategies and no proxies, fail closed to empty ensemble
        if ($filtered === [] && $pluginEvaluations !== []) {
            return [
                'direction' => 'NEUTRAL',
                'confluence_score' => 0.0,
                'families' => [],
                'evidence' => [],
                'conflicts' => [['type' => 'NO_APPROVED_STRATEGIES', 'detail' => 'No governance-approved evaluations']],
                'family_diversity' => 0,
                'diversity_bonus' => 0.0,
                'breakdown' => [],
                'disclaimer' => 'Ensemble requires Phase 16 APPROVED/DEPLOYED_DEMO versions (or intel proxies). Advisory only.',
                'execution_authority' => false,
                'governance_filter' => [
                    'approved_versions' => $approved->count(),
                    'rejected' => $rejected,
                    'status' => 'NO_APPROVED_INPUT',
                ],
                'phase_16_governance_mandatory' => true,
            ];
        }

        $ens = $this->base->build($filtered, $preferredDirection);
        $ens['governance_filter'] = [
            'approved_versions' => $approved->count(),
            'approved_keys' => array_values($approvedKeys),
            'rejected' => $rejected,
            'included' => count($filtered),
            'status' => 'OK',
        ];
        $ens['phase_16_governance_mandatory'] = true;
        $ens['execution_authority'] = false;
        $ens['disclaimer'] = ($ens['disclaimer'] ?? 'Advisory ensemble.')
            .' Phase 16 governance filter applied — not an execution signal.';

        return $ens;
    }
}
