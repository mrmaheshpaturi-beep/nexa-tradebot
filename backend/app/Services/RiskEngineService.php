<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Enums\ProposedPlanStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskReasonCode;
use App\Enums\TradeIntentStatus;
use App\Models\ProposedPlan;
use App\Models\RiskDecision;
use App\Models\RiskEvent;
use App\Models\RiskRuleDefinition;
use App\Models\ServiceHeartbeat;
use App\Models\SystemEvent;
use App\Models\TradeIntent;
use App\Risk\RiskEvaluationContext;
use App\Risk\RiskRuleRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Authoritative server-side RiskEngine.
 * Produces immutable RiskDecision + ProposedPlan only. Never creates broker orders.
 */
class RiskEngineService
{
    public function __construct(
        private readonly RiskRuleRegistry $registry,
        private readonly PositionSizingService $sizing,
        private readonly RiskAccountContextBuilder $accountContext,
        private readonly RiskReservationService $reservations,
        private readonly RiskLockService $locks,
        private readonly MarketDataProvider $marketData,
        private readonly SpreadEngine $spreads,
        private readonly MarketSessionService $sessions,
    ) {}

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        if ($intent->riskDecision()->exists()) {
            return $intent->riskDecision()->firstOrFail();
        }

        return DB::transaction(function () use ($intent): RiskDecision {
            $intent = TradeIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($intent->riskDecision()->exists()) {
                return $intent->riskDecision()->firstOrFail();
            }

            $intent->loadMissing(['brokerAccount.riskProfile', 'instrument', 'strategy', 'user']);
            $account = $intent->brokerAccount;
            $profile = $account?->riskProfile;
            $instrument = $intent->instrument;

            try {
                if ($account === null || $profile === null || $instrument === null) {
                    return $this->persistFailClosed($intent, null, RiskReasonCode::FailClosed, 'Missing account, profile, or instrument; fail closed.');
                }

                $this->reservations->expireDue();
                $this->pulseHeartbeat();

                $accountCtx = $this->accountContext->build($account, $instrument);
                if ($accountCtx['balance'] <= 0 && $accountCtx['equity'] <= 0) {
                    return $this->persistFailClosed($intent, $profile, RiskReasonCode::MissingSnapshot, 'An account snapshot with equity/balance is required.');
                }

                $rawQuote = $this->marketData->getQuote($instrument->symbol);
                $bid = (float) ($rawQuote['bid'] ?? 0);
                $ask = (float) ($rawQuote['ask'] ?? 0);
                $spread = $this->spreads->calculate((string) $bid, (string) $ask, (int) $instrument->digits);
                $sessionInfo = $this->sessions->sessions();
                $marketStatus = $this->sessions->marketStatus($instrument->symbol, $instrument->asset_class?->value);

                $quote = [
                    'bid' => $bid,
                    'ask' => $ask,
                    'spread_raw' => (float) $spread['raw'],
                    'spread_points' => $spread['points'] !== null ? (float) $spread['points'] : null,
                    'digits' => (int) $instrument->digits,
                    'source' => (string) ($rawQuote['source'] ?? 'MOCK'),
                    'quality' => $bid > 0 && $ask > 0 && $ask >= $bid ? 'OK' : 'BAD',
                ];

                $symbolSpecs = [
                    'symbol' => $instrument->symbol,
                    'digits' => (int) $instrument->digits,
                    'point_size' => (float) $instrument->point_size,
                    'contract_size' => (float) $instrument->contract_size,
                    'tick_size' => (float) $instrument->tick_size,
                    'tick_value' => (float) ($instrument->tick_value ?? 0),
                    'minimum_volume' => (float) $instrument->minimum_volume,
                    'maximum_volume' => (float) $instrument->maximum_volume,
                    'step_volume' => (float) $instrument->step_volume,
                    'minimum_stop_distance' => (float) ($instrument->minimum_stop_distance ?? 0),
                    'margin_rate' => (float) $instrument->margin_rate,
                    'atr' => null,
                    'active_sessions' => $sessionInfo['active'] ?? [],
                    'market_status' => $marketStatus['status'] ?? null,
                ];

                $sizing = $this->sizing->propose($intent, $instrument, $profile, $quote, [
                    'balance' => $accountCtx['balance'],
                    'equity' => $accountCtx['equity'],
                    'leverage' => $accountCtx['leverage'],
                ]);

                $context = new RiskEvaluationContext(
                    intent: $intent,
                    profile: $profile,
                    instrument: $instrument,
                    accountContext: $accountCtx,
                    quote: $quote,
                    symbolSpecs: $symbolSpecs,
                    sizing: $sizing,
                    engineVersion: RiskRuleRegistry::ENGINE_VERSION,
                    rulesBundleVersion: $profile->rules_bundle_version ?: RiskRuleRegistry::BUNDLE_VERSION,
                    activeLocks: $this->locks->activeLockCodes($account, $intent->user),
                );

                foreach ($this->registry->rules() as $rule) {
                    if ($context->isBlocked()) {
                        break;
                    }
                    $rule->evaluate($context);
                }

                $approved = ! $context->isBlocked();
                $reason = $approved ? RiskReasonCode::Approved : ($context->blockingReason ?? RiskReasonCode::FailClosed);
                $message = $approved
                    ? 'All deterministic RiskEngine checks passed.'
                    : ($context->blockingMessage ?? 'Risk evaluation failed closed.');

                $decision = RiskDecision::query()->create([
                    'trade_intent_id' => $intent->id,
                    'risk_profile_id' => $profile->id,
                    'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
                    'profile_version' => (int) $profile->version,
                    'rules_bundle_version' => $context->rulesBundleVersion,
                    'config_hash' => $profile->config_hash ?: $profile->configHash(),
                    'status' => $approved ? RiskDecisionStatus::Approved : RiskDecisionStatus::Rejected,
                    'decision' => $approved ? RiskDecisionStatus::Approved : RiskDecisionStatus::Rejected,
                    'reason_code' => $reason,
                    'message' => $message,
                    'reason' => $message,
                    'risk_amount' => $sizing['proposed_risk_amount'],
                    'requested_risk' => $sizing['proposed_risk_amount'],
                    'approved_risk' => $approved ? $sizing['proposed_risk_amount'] : null,
                    'requested_volume' => $intent->requested_volume,
                    'approved_volume' => $approved ? $sizing['proposed_volume'] : null,
                    'reward_risk' => $sizing['proposed_reward_risk'],
                    'checks' => [
                        'environment' => $intent->environment->value,
                        'account' => $account->public_id,
                        'symbol' => $instrument->symbol,
                        'volume' => $sizing['proposed_volume'],
                        'risk_percent' => $sizing['proposed_risk_percent'],
                        'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
                        'profile_version' => (int) $profile->version,
                        'order_send' => false,
                        'broker_routable' => false,
                    ],
                    'rule_results' => $context->ruleResults,
                    'account_context' => $accountCtx,
                    'symbol_context' => $symbolSpecs,
                    'immutable' => true,
                    'evaluated_at' => now(),
                ]);

                $plan = ProposedPlan::query()->create([
                    'risk_decision_id' => $decision->id,
                    'trade_intent_id' => $intent->id,
                    'broker_account_id' => $account->id,
                    'trading_instrument_id' => $instrument->id,
                    'status' => $approved ? ProposedPlanStatus::Proposed : ProposedPlanStatus::Rejected,
                    'proposed_volume' => $sizing['proposed_volume'],
                    'proposed_risk_amount' => $sizing['proposed_risk_amount'],
                    'proposed_risk_percent' => $sizing['proposed_risk_percent'],
                    'proposed_entry' => $sizing['proposed_entry'],
                    'proposed_stop_loss' => $sizing['proposed_stop_loss'],
                    'proposed_take_profit' => $sizing['proposed_take_profit'],
                    'proposed_reward_risk' => $sizing['proposed_reward_risk'],
                    'proposed_margin' => $sizing['proposed_margin'],
                    'sizing_breakdown' => $sizing['breakdown'],
                    'symbol_specs' => $symbolSpecs,
                    'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
                    'profile_version' => (string) $profile->version,
                    'broker_routable' => false,
                ]);
                $decision->setAttribute('proposed_plan_id', $plan->id);
                DB::table('risk_decisions')->where('id', $decision->id)->update(['proposed_plan_id' => $plan->id]);

                if ($approved) {
                    $this->reservations->reserve(
                        $intent->user,
                        $account,
                        $intent,
                        'risk:'.$intent->public_id.':'.$intent->idempotency_key,
                        [
                            'reserved_margin' => $sizing['proposed_margin'],
                            'reserved_risk' => $sizing['proposed_risk_amount'],
                            'reserved_exposure' => $sizing['proposed_risk_percent'],
                            'symbol' => $instrument->symbol,
                        ],
                        $decision,
                    );
                    $plan->update(['status' => ProposedPlanStatus::AcceptedForSimulation]);
                } else {
                    $this->locks->maybeAutoLockFromReason(
                        $intent->user,
                        $account,
                        $profile,
                        $reason,
                        $message,
                        ['decision_public_id' => $decision->public_id],
                    );
                    $this->recordBreachEvent($profile, $reason, $message, $decision);
                }

                RiskEvent::query()->create([
                    'risk_profile_id' => $profile->id,
                    'order_id' => null,
                    'rule' => $reason->value,
                    'severity' => $approved ? 'INFO' : 'WARNING',
                    'decision' => $decision->decision->value,
                    'message' => $message,
                    'context' => [
                        'decision_public_id' => $decision->public_id,
                        'plan_public_id' => $plan->public_id,
                        'rule_results' => $context->ruleResults,
                    ],
                    'occurred_at' => now(),
                ]);

                $intent->transitionTo($approved ? TradeIntentStatus::RiskApproved : TradeIntentStatus::RiskRejected);

                return $decision->load('proposedPlan');
            } catch (Throwable $e) {
                report($e);

                return $this->persistFailClosed(
                    $intent,
                    $profile ?? null,
                    RiskReasonCode::FailClosed,
                    'RiskEngine exception; fail closed: '.str($e->getMessage())->limit(160),
                );
            }
        });
    }

    public function health(): array
    {
        $hb = ServiceHeartbeat::query()->where('service', 'RISK_ENGINE')->latest('observed_at')->first();

        return [
            'phase' => 9,
            'status' => 'READY',
            'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
            'rules_bundle_version' => RiskRuleRegistry::BUNDLE_VERSION,
            'rules' => $this->registry->catalog(),
            'authoritative' => true,
            'fail_closed' => true,
            'broker_routable' => false,
            'order_send' => false,
            'demo_execution' => false,
            'live_execution' => false,
            'heartbeat' => $hb,
            'evaluate_api' => '/api/v1/risk-engine/evaluate/{tradeIntent}',
            'dashboard_api' => '/api/v1/risk-engine/dashboard',
        ];
    }

    public function ensureRuleDefinitionsSeeded(): void
    {
        foreach ($this->registry->catalog() as $rule) {
            RiskRuleDefinition::query()->updateOrCreate(
                ['code' => $rule['code']],
                [
                    'name' => str_replace('_', ' ', $rule['code']),
                    'version' => $rule['version'],
                    'enabled' => true,
                    'priority' => $rule['priority'],
                    'description' => 'Phase 9 modular risk rule '.$rule['code'],
                ],
            );
        }
    }

    private function persistFailClosed(TradeIntent $intent, $profile, RiskReasonCode $code, string $message): RiskDecision
    {
        $decision = RiskDecision::query()->create([
            'trade_intent_id' => $intent->id,
            'risk_profile_id' => $profile?->id,
            'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
            'profile_version' => $profile?->version,
            'rules_bundle_version' => RiskRuleRegistry::BUNDLE_VERSION,
            'config_hash' => $profile?->config_hash,
            'status' => RiskDecisionStatus::Rejected,
            'decision' => RiskDecisionStatus::Rejected,
            'reason_code' => $code,
            'message' => $message,
            'reason' => $message,
            'risk_amount' => 0,
            'requested_risk' => 0,
            'approved_risk' => null,
            'requested_volume' => $intent->requested_volume,
            'approved_volume' => null,
            'reward_risk' => null,
            'checks' => ['fail_closed' => true, 'order_send' => false],
            'rule_results' => [['code' => 'FAIL_CLOSED', 'passed' => false, 'reason' => $message, 'evidence' => []]],
            'account_context' => null,
            'symbol_context' => null,
            'immutable' => true,
            'evaluated_at' => now(),
        ]);
        $intent->transitionTo(TradeIntentStatus::RiskRejected);

        return $decision;
    }

    private function recordBreachEvent($profile, RiskReasonCode $reason, string $message, RiskDecision $decision): void
    {
        $recent = SystemEvent::query()
            ->where('category', 'RISK')
            ->where('message', 'like', 'risk.breach.'.$reason->value.'%')
            ->where('occurred_at', '>=', now()->subMinutes(5))
            ->exists();
        if ($recent) {
            return;
        }
        SystemEvent::query()->create([
            'level' => 'WARNING',
            'category' => 'RISK',
            'message' => 'risk.breach.'.$reason->value.': '.$message,
            'context' => [
                'event' => 'risk.breach',
                'reason_code' => $reason->value,
                'decision_public_id' => $decision->public_id,
                'profile_id' => $profile?->id,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function pulseHeartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'RISK_ENGINE',
            'instance_id' => 'phase-9-risk-engine',
            'status' => 'ONLINE',
            'environment' => 'SIMULATION',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => [
                'engine_version' => RiskRuleRegistry::ENGINE_VERSION,
                'order_send' => false,
            ],
            'metadata' => ['phase' => 9],
        ]);
    }
}
