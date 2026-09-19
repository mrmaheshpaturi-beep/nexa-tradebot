<?php

namespace App\Services;

use App\Models\RiskDecision;
use App\Models\TradeIntent;

/**
 * Backward-compatible Phase 3 entrypoint. Authoritative evaluation is RiskEngineService.
 */
class SimulationRiskEvaluator
{
    public function __construct(private readonly RiskEngineService $engine) {}

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        return $this->engine->evaluate($intent);
    }
}
