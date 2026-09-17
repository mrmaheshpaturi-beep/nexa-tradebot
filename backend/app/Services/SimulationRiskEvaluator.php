<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskReasonCode;
use App\Enums\TradeIntentStatus;
use App\Enums\TradingEnvironment;
use App\Models\RiskDecision;
use App\Models\TradeIntent;

class SimulationRiskEvaluator
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MarketDataProvider $marketData,
        private readonly FinancialCalculator $calculator,
    ) {}

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        if ($intent->riskDecision()->exists()) {
            return $intent->riskDecision()->firstOrFail();
        }

        $intent->loadMissing(['brokerAccount.riskProfile', 'instrument', 'strategy']);
        $account = $intent->brokerAccount;
        $profile = $account->riskProfile;
        $reason = RiskReasonCode::Approved;
        $message = 'All deterministic simulation risk checks passed.';

        if ($intent->environment !== TradingEnvironment::Simulation || $account->environment !== TradingEnvironment::Simulation) {
            [$reason, $message] = [RiskReasonCode::InvalidEnvironment, 'Only SIMULATION intents can be approved.'];
        } elseif ($this->settings->value('emergency_stop') !== false) {
            [$reason, $message] = [RiskReasonCode::EmergencyStop, 'Emergency stop is active.'];
        } elseif ($this->settings->value('simulation_execution_enabled') !== true) {
            [$reason, $message] = [RiskReasonCode::SimulationDisabled, 'Simulation execution is disabled.'];
        } elseif (! $account->is_enabled || $profile === null || $profile->status !== 'ACTIVE') {
            [$reason, $message] = [RiskReasonCode::InvalidAccount, 'The account or its risk profile is not active.'];
        } elseif (! $intent->instrument->is_enabled
            || ($intent->strategy && ! in_array($intent->instrument->symbol, $intent->strategy->symbols, true))) {
            [$reason, $message] = [RiskReasonCode::InvalidInstrument, 'The instrument is disabled.'];
        } elseif (! $this->calculator->isVolumeValid($intent->instrument, (float) $intent->requested_volume)
            || (float) $intent->requested_volume > (float) $profile->max_lot_size) {
            [$reason, $message] = [RiskReasonCode::InvalidVolume, 'Volume violates the instrument or profile limit.'];
        } elseif ($account->positions()->whereIn('status', ['OPEN', 'PARTIALLY_CLOSED'])->count() >= $profile->max_open_positions) {
            [$reason, $message] = [RiskReasonCode::MaxOpenPositions, 'Maximum open positions reached.'];
        } elseif ($account->snapshots()->latest('captured_at')->latest('id')->first() === null) {
            [$reason, $message] = [RiskReasonCode::MissingSnapshot, 'An account snapshot is required.'];
        }

        $quote = $this->marketData->getQuote($intent->instrument->symbol);
        $entry = (float) ($intent->requested_entry ?? ($intent->side->value === 'BUY' ? $quote['ask'] : $quote['bid']));
        $riskAmount = $this->calculator->riskAmount(
            $intent->instrument,
            (float) $intent->requested_volume,
            $entry,
            $intent->stop_loss === null ? null : (float) $intent->stop_loss,
        );
        $rewardRisk = $this->calculator->rewardRisk(
            $intent->side,
            $entry,
            $intent->stop_loss === null ? null : (float) $intent->stop_loss,
            $intent->take_profit === null ? null : (float) $intent->take_profit,
        );
        $protectionInvalid = ($intent->side->value === 'BUY'
            && (($intent->stop_loss !== null && (float) $intent->stop_loss >= $entry)
                || ($intent->take_profit !== null && (float) $intent->take_profit <= $entry)))
            || ($intent->side->value === 'SELL'
                && (($intent->stop_loss !== null && (float) $intent->stop_loss <= $entry)
                    || ($intent->take_profit !== null && (float) $intent->take_profit >= $entry)));
        $snapshot = $account->snapshots()->latest('captured_at')->latest('id')->first();
        $calculatedRiskPercent = $snapshot && (float) $snapshot->balance > 0.00000001
            ? $riskAmount / (float) $snapshot->balance * 100
            : 0;
        $riskPercent = max($calculatedRiskPercent, (float) ($intent->risk_percent ?? 0));

        if ($reason === RiskReasonCode::Approved && $protectionInvalid) {
            [$reason, $message] = [RiskReasonCode::InvalidProtection, 'Stop loss or take profit is on the invalid side of entry.'];
        } elseif ($reason === RiskReasonCode::Approved && $riskPercent > (float) $profile->max_risk_per_trade + 0.00000001) {
            [$reason, $message] = [RiskReasonCode::RiskLimit, 'Calculated risk exceeds the profile limit.'];
        } elseif ($reason === RiskReasonCode::Approved && $rewardRisk !== null
            && $rewardRisk + 0.00000001 < (float) $profile->min_reward_risk) {
            [$reason, $message] = [RiskReasonCode::MinimumRiskReward, 'Reward/risk is below the profile minimum.'];
        }

        $approved = $reason === RiskReasonCode::Approved;
        $decision = $intent->riskDecision()->create([
            'risk_profile_id' => $profile?->id,
            'status' => $approved ? RiskDecisionStatus::Approved : RiskDecisionStatus::Rejected,
            'decision' => $approved ? RiskDecisionStatus::Approved : RiskDecisionStatus::Rejected,
            'reason_code' => $reason,
            'message' => $message,
            'reason' => $message,
            'risk_amount' => $riskAmount,
            'requested_risk' => $riskAmount,
            'approved_risk' => $approved ? $riskAmount : null,
            'requested_volume' => $intent->requested_volume,
            'approved_volume' => $approved ? $intent->requested_volume : null,
            'reward_risk' => $rewardRisk,
            'checks' => [
                'environment' => $intent->environment->value,
                'account' => $account->public_id,
                'symbol' => $intent->instrument->symbol,
                'volume' => $intent->requested_volume,
                'risk_percent' => round($riskPercent, 4),
            ],
            'evaluated_at' => now(),
        ]);
        $intent->transitionTo($approved ? TradeIntentStatus::RiskApproved : TradeIntentStatus::RiskRejected);

        return $decision;
    }
}
