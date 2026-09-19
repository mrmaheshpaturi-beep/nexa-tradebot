<?php

namespace App\Risk\Rules;

use App\Enums\OrderDirection;
use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;
use App\Services\FinancialCalculator;

final class StopAndRewardRiskRule implements RiskRule
{
    public function __construct(private readonly FinancialCalculator $calculator) {}

    public function code(): string
    {
        return 'STOP_AND_RR';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 25;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $intent = $context->intent;
        $profile = $context->profile;
        $entry = (float) $context->sizing['proposed_entry'];
        $stop = $context->sizing['proposed_stop_loss'];
        $take = $context->sizing['proposed_take_profit'];
        $side = $intent->side instanceof OrderDirection ? $intent->side : OrderDirection::from((string) $intent->side);

        if ($profile->require_stop_loss && $stop === null) {
            $context->fail(RiskReasonCode::InvalidProtection, 'Stop loss is required by the risk profile.', [], $this->code());

            return;
        }

        if ($stop !== null) {
            $stop = (float) $stop;
            $protectionInvalid = ($side === OrderDirection::Buy && $stop >= $entry)
                || ($side === OrderDirection::Sell && $stop <= $entry);
            if ($protectionInvalid) {
                $context->fail(RiskReasonCode::InvalidProtection, 'Stop loss is on the invalid side of entry.', [
                    'entry' => $entry,
                    'stop_loss' => $stop,
                ], $this->code());

                return;
            }

            $minStop = (float) ($context->symbolSpecs['minimum_stop_distance'] ?? 0);
            $distance = abs($entry - $stop);
            if ($minStop > 0 && $distance + 0.00000001 < $minStop) {
                $context->fail(RiskReasonCode::StopDistance, 'Stop distance is below symbol minimum.', [
                    'distance' => $distance,
                    'minimum_stop_distance' => $minStop,
                ], $this->code());

                return;
            }

            $atrMult = $profile->atr_stop_multiplier;
            $atr = $context->symbolSpecs['atr'] ?? null;
            if ($atrMult !== null && $atr !== null && (float) $atr > 0) {
                $minAtrStop = (float) $atr * (float) $atrMult;
                if ($distance + 0.00000001 < $minAtrStop) {
                    $context->fail(RiskReasonCode::StopDistance, 'Stop distance is below ATR-aware minimum.', [
                        'distance' => $distance,
                        'atr' => (float) $atr,
                        'atr_stop_multiplier' => (float) $atrMult,
                        'min_atr_stop' => $minAtrStop,
                    ], $this->code());

                    return;
                }
            }
        }

        if ($take !== null) {
            $take = (float) $take;
            $tpInvalid = ($side === OrderDirection::Buy && $take <= $entry)
                || ($side === OrderDirection::Sell && $take >= $entry);
            if ($tpInvalid) {
                $context->fail(RiskReasonCode::InvalidProtection, 'Take profit is on the invalid side of entry.', [
                    'entry' => $entry,
                    'take_profit' => $take,
                ], $this->code());

                return;
            }
        }

        $rr = $this->calculator->rewardRisk($side, $entry, $stop === null ? null : (float) $stop, $take === null ? null : (float) $take);
        if ($rr !== null && $rr + 0.00000001 < (float) $profile->min_reward_risk) {
            $context->fail(RiskReasonCode::MinimumRiskReward, 'Reward/risk is below the profile minimum.', [
                'reward_risk' => $rr,
                'min_reward_risk' => (float) $profile->min_reward_risk,
            ], $this->code());

            return;
        }

        $context->pass($this->code(), ['reward_risk' => $rr, 'stop_distance' => $stop === null ? null : abs($entry - (float) $stop)]);
    }
}
