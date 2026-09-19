<?php

namespace App\TradeManagement;

use App\TradeManagement\Contracts\TradeManagementRule;
use App\TradeManagement\Rules\BreakEvenRule;
use App\TradeManagement\Rules\EmergencyExitRule;
use App\TradeManagement\Rules\PartialCloseRule;
use App\TradeManagement\Rules\RiskExitRule;
use App\TradeManagement\Rules\SessionExitRule;
use App\TradeManagement\Rules\StrategyInvalidationRule;
use App\TradeManagement\Rules\TakeProfitRule;
use App\TradeManagement\Rules\TimeExitRule;
use App\TradeManagement\Rules\TrailingStopRule;

/**
 * Deterministic priority (Phase 11 §12):
 * 10 EmergencyExit
 * 20 RiskExit
 * 30 StrategyInvalidation
 * 40 TimeExit
 * 45 SessionExit / Weekend
 * 50 PartialClose
 * 60 BreakEven
 * 70 TrailingStop
 * 80 TakeProfit
 * HOLD is default when no rule fires
 */
class TradeManagementRuleRegistry
{
    /** @return list<TradeManagementRule> */
    public function rules(): array
    {
        $rules = [
            new EmergencyExitRule,
            new RiskExitRule,
            new StrategyInvalidationRule,
            new TimeExitRule,
            new SessionExitRule,
            new PartialCloseRule,
            new BreakEvenRule,
            new TrailingStopRule,
            new TakeProfitRule,
        ];
        usort($rules, fn (TradeManagementRule $a, TradeManagementRule $b) => $a->priority() <=> $b->priority());

        return $rules;
    }
}
