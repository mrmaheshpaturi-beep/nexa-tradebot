<?php

namespace App\Risk;

use App\Enums\RiskReasonCode;
use App\Models\RiskProfile;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;

/**
 * Immutable evaluation context shared across versioned risk rules.
 *
 * @phpstan-type AccountContext array{
 *   balance:float,
 *   equity:float,
 *   margin:float,
 *   free_margin:float,
 *   margin_level:float|null,
 *   floating_pnl:float,
 *   drawdown:float,
 *   open_positions:int,
 *   reserved_margin:float,
 *   reserved_risk:float,
 *   reserved_exposure:float,
 *   daily_realized_pnl:float,
 *   weekly_realized_pnl:float,
 *   consecutive_losses:int,
 *   trades_today:int,
 *   open_risk_percent:float,
 *   correlated_exposure_percent:float,
 *   leverage:int
 * }
 * @phpstan-type QuoteContext array{
 *   bid:float,
 *   ask:float,
 *   spread_raw:float,
 *   spread_points:float|null,
 *   digits:int,
 *   source:string,
 *   quality:string
 * }
 * @phpstan-type SizingPlan array{
 *   proposed_volume:float,
 *   proposed_risk_amount:float,
 *   proposed_risk_percent:float,
 *   proposed_entry:float,
 *   proposed_stop_loss:float|null,
 *   proposed_take_profit:float|null,
 *   proposed_reward_risk:float|null,
 *   proposed_margin:float,
 *   breakdown:array<string,mixed>
 * }
 */
final class RiskEvaluationContext
{
    /**
     * @param  AccountContext  $accountContext
     * @param  QuoteContext  $quote
     * @param  array<string,mixed>  $symbolSpecs
     * @param  SizingPlan  $sizing
     * @param  list<array{code:string,passed:bool,reason:?string,evidence:array<string,mixed>}>  $ruleResults
     * @param  list<string>  $activeLocks
     */
    public function __construct(
        public readonly TradeIntent $intent,
        public readonly RiskProfile $profile,
        public readonly TradingInstrument $instrument,
        public readonly array $accountContext,
        public readonly array $quote,
        public readonly array $symbolSpecs,
        public readonly array $sizing,
        public readonly string $engineVersion,
        public readonly string $rulesBundleVersion,
        public array $ruleResults = [],
        public array $activeLocks = [],
        public ?RiskReasonCode $blockingReason = null,
        public ?string $blockingMessage = null,
    ) {}

    public function fail(RiskReasonCode $code, string $message, array $evidence = [], string $ruleCode = 'FAIL_CLOSED'): void
    {
        if ($this->blockingReason === null) {
            $this->blockingReason = $code;
            $this->blockingMessage = $message;
        }
        $this->ruleResults[] = [
            'code' => $ruleCode,
            'passed' => false,
            'reason' => $message,
            'evidence' => $evidence,
        ];
    }

    public function pass(string $ruleCode, array $evidence = [], ?string $reason = null): void
    {
        $this->ruleResults[] = [
            'code' => $ruleCode,
            'passed' => true,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }

    public function isBlocked(): bool
    {
        return $this->blockingReason !== null;
    }
}
