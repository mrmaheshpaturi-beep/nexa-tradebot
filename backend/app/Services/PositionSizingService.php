<?php

namespace App\Services;

use App\Enums\OrderDirection;
use App\Models\RiskProfile;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;

/**
 * Symbol-aware position sizing. Produces proposals only — never broker orders.
 */
class PositionSizingService
{
    private const EPSILON = 0.00000001;

    public function __construct(private readonly FinancialCalculator $calculator) {}

    /**
     * @param  array{bid:float,ask:float,digits:int}  $quote
     * @param  array{balance:float,equity:float,leverage:int}  $account
     * @return array{
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
    public function propose(TradeIntent $intent, TradingInstrument $instrument, RiskProfile $profile, array $quote, array $account): array
    {
        $side = $intent->side instanceof OrderDirection ? $intent->side : OrderDirection::from((string) $intent->side);
        $entry = (float) ($intent->requested_entry ?? ($side === OrderDirection::Buy ? $quote['ask'] : $quote['bid']));
        $stop = $intent->stop_loss === null ? null : (float) $intent->stop_loss;
        $take = $intent->take_profit === null ? null : (float) $intent->take_profit;
        $requested = (float) $intent->requested_volume;
        $equity = max((float) ($account['equity'] ?? 0), (float) ($account['balance'] ?? 0), 0.0);
        $targetRiskPercent = min(
            (float) ($intent->risk_percent ?? $profile->max_risk_per_trade),
            (float) $profile->max_risk_per_trade,
        );
        $targetRiskAmount = $equity * ($targetRiskPercent / 100);

        $volume = $requested;
        $sizingMode = 'REQUESTED_VOLUME';

        if ($profile->sizing_enabled !== false && $stop !== null && abs($entry - $stop) > self::EPSILON && $equity > 0) {
            $stopDistance = abs($entry - $stop);
            $contract = (float) $instrument->contract_size;
            if ($contract > 0) {
                $rawVolume = $targetRiskAmount / ($stopDistance * $contract);
                $volume = $this->clampToInstrumentStep($instrument, $rawVolume);
                $volume = min($volume, (float) $profile->max_lot_size, (float) $instrument->maximum_volume);
                $volume = max($volume, 0.0);
                // Prefer safer of requested vs sized when sizing is enabled.
                if ($requested > 0) {
                    $volume = min($volume, $requested);
                }
                $sizingMode = 'EQUITY_STOP_DISTANCE';
            }
        }

        if (! $this->calculator->isVolumeValid($instrument, $volume) && $volume > 0) {
            $volume = $this->clampToInstrumentStep($instrument, $volume);
        }

        $riskAmount = $this->calculator->riskAmount($instrument, $volume, $entry, $stop);
        $riskPercent = $equity > self::EPSILON ? ($riskAmount / $equity) * 100 : 0.0;
        $rr = $this->calculator->rewardRisk($side, $entry, $stop, $take);
        $margin = $this->calculator->margin(
            $instrument,
            $volume,
            $entry,
            max(1, (int) ($account['leverage'] ?? 1)),
        );

        return [
            'proposed_volume' => round($volume, 4),
            'proposed_risk_amount' => $riskAmount,
            'proposed_risk_percent' => round($riskPercent, 4),
            'proposed_entry' => $entry,
            'proposed_stop_loss' => $stop,
            'proposed_take_profit' => $take,
            'proposed_reward_risk' => $rr,
            'proposed_margin' => $margin,
            'breakdown' => [
                'mode' => $sizingMode,
                'requested_volume' => $requested,
                'target_risk_percent' => $targetRiskPercent,
                'target_risk_amount' => round($targetRiskAmount, 4),
                'equity' => $equity,
                'contract_size' => (float) $instrument->contract_size,
                'digits' => (int) $instrument->digits,
                'tick_size' => (float) $instrument->tick_size,
                'tick_value' => (float) ($instrument->tick_value ?? 0),
                'volume_min' => (float) $instrument->minimum_volume,
                'volume_max' => (float) $instrument->maximum_volume,
                'volume_step' => (float) $instrument->step_volume,
                'no_hardcoded_pips' => true,
            ],
        ];
    }

    public function clampToInstrumentStep(TradingInstrument $instrument, float $volume): float
    {
        $min = (float) $instrument->minimum_volume;
        $max = (float) $instrument->maximum_volume;
        $step = (float) $instrument->step_volume;
        if ($step <= 0) {
            return 0.0;
        }
        if ($volume < $min - self::EPSILON) {
            return 0.0;
        }
        $steps = floor((($volume - $min) / $step) + self::EPSILON);
        $clamped = $min + ($steps * $step);
        if ($clamped > $max + self::EPSILON) {
            $steps = floor((($max - $min) / $step) + self::EPSILON);
            $clamped = $min + ($steps * $step);
        }

        return round(max(0, $clamped), 4);
    }
}
