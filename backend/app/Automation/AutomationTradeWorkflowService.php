<?php

namespace App\Automation;

use App\Enums\AutomationMode;
use App\Enums\AutomationState;
use App\Enums\AutomationWorkflowState;
use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\RiskDecisionStatus;
use App\Enums\TradeIntentStatus;
use App\Enums\TradeOrigin;
use App\Enums\TradingEnvironment;
use App\Execution\ExecutionConfirmationService;
use App\Execution\ExecutionEngineService;
use App\Models\AutomationProfile;
use App\Models\AutomationSession;
use App\Models\AutomationWorkflow;
use App\Models\IntelligenceAssessment;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use App\Models\User;
use App\Services\RiskEngineService;
use App\TradeManagement\TradeManagementEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomationTradeWorkflowService
{
    public function __construct(
        private readonly AutomatedCandidateQualificationEngine $qualification,
        private readonly AutomationEventRecorder $events,
        private readonly RiskEngineService $risk,
        private readonly ExecutionConfirmationService $confirmations,
        private readonly ExecutionEngineService $execution,
        private readonly AutomationStartupGate $gate,
    ) {}

    /**
     * @param  array<string,mixed>  $candidate
     */
    public function startFromCandidate(
        User $user,
        AutomationSession $session,
        AutomationProfile $profile,
        array $candidate,
        ?IntelligenceAssessment $intelligence = null,
    ): AutomationWorkflow {
        $fp = $this->qualification->fingerprint($candidate);
        $existing = AutomationWorkflow::query()
            ->where('automation_session_id', $session->id)
            ->where('fingerprint', $fp)
            ->first();
        if ($existing) {
            $this->events->record($user, $session, 'WORKFLOW_DUPLICATE_BLOCKED', [
                'fingerprint' => $fp,
                'existing' => $existing->public_id,
            ], 'WARN', $existing->id);

            return $existing;
        }

        $workflow = AutomationWorkflow::query()->create([
            'user_id' => $user->id,
            'automation_session_id' => $session->id,
            'automation_profile_id' => $profile->id,
            'fingerprint' => $fp,
            'state' => AutomationWorkflowState::CandidateReceived,
            'symbol' => strtoupper((string) ($candidate['symbol'] ?? '')),
            'timeframe' => $candidate['timeframe'] ?? null,
            'direction' => strtoupper((string) ($candidate['direction'] ?? $candidate['side'] ?? '')),
            'strategy_key' => $candidate['strategy_key'] ?? $candidate['strategy'] ?? null,
            'strategy_version' => $candidate['strategy_version'] ?? 'v1',
            'signal_candidate_id' => $candidate['signal_candidate_id'] ?? null,
            'intelligence_assessment_id' => $intelligence?->id,
            'dry_run' => $session->mode === AutomationMode::DryRun,
            'broker_touched' => false,
            'trace' => [['at' => now()->toIso8601String(), 'state' => 'CANDIDATE_RECEIVED']],
            'payload' => $candidate,
            'signal_at' => isset($candidate['signal_at']) ? $candidate['signal_at'] : now(),
            'expires_at' => now()->addSeconds(max(60, (int) $profile->signal_ttl_seconds)),
        ]);

        return $this->advance($user, $session, $profile, $workflow, $intelligence);
    }

    public function advance(
        User $user,
        AutomationSession $session,
        AutomationProfile $profile,
        AutomationWorkflow $workflow,
        ?IntelligenceAssessment $intelligence = null,
    ): AutomationWorkflow {
        if ($workflow->expires_at && $workflow->expires_at->isPast()
            && ! in_array($workflow->state, [
                AutomationWorkflowState::Closed,
                AutomationWorkflowState::Rejected,
                AutomationWorkflowState::Expired,
                AutomationWorkflowState::DryRunComplete,
                AutomationWorkflowState::ExecutionFilled,
                AutomationWorkflowState::ManagementActive,
            ], true)) {
            return $this->transition($workflow, AutomationWorkflowState::Expired, 'TTL');
        }

        $result = $this->qualification->qualify(
            $session,
            $profile,
            array_merge($workflow->payload ?? [], [
                'symbol' => $workflow->symbol,
                'timeframe' => $workflow->timeframe,
                'direction' => $workflow->direction,
                'strategy_key' => $workflow->strategy_key,
                'strategy_version' => $workflow->strategy_version,
                'signal_at' => $workflow->signal_at?->toIso8601String(),
            ]),
            $intelligence ?? ($workflow->intelligence_assessment_id
                ? IntelligenceAssessment::query()->find($workflow->intelligence_assessment_id)
                : null),
        );

        $workflow->qualification = $result['qualification'];
        $workflow->save();

        if ($result['decision'] === 'WAIT') {
            return $this->transition($workflow, AutomationWorkflowState::IntelligenceWait, $result['code'] ?? 'WAIT');
        }
        if ($result['decision'] !== 'QUALIFIED') {
            $workflow->rejection_code = $result['code'];
            $workflow->rejection_stage = 'QUALIFICATION';
            $workflow->rejection_detail = $result['detail'];
            $workflow->completed_at = now();
            $workflow->save();
            $this->events->record($user, $session, 'WORKFLOW_REJECTED', [
                'code' => $result['code'],
                'detail' => $result['detail'],
            ], 'WARN', $workflow->id);

            return $this->transition($workflow, $result['next_state'], $result['code'] ?? 'REJECT');
        }

        $this->transition($workflow, AutomationWorkflowState::Qualified, 'QUALIFIED');

        // DRY_RUN — zero broker
        if ($session->mode === AutomationMode::DryRun || $workflow->dry_run) {
            $workflow->forceFill([
                'state' => AutomationWorkflowState::DryRunComplete,
                'completed_at' => now(),
                'broker_touched' => false,
            ])->save();
            $this->appendTrace($workflow, 'DRY_RUN_COMPLETE');
            $this->events->record($user, $session, 'WORKFLOW_DRY_RUN', [
                'workflow' => $workflow->public_id,
                'broker_touched' => false,
            ], 'INFO', $workflow->id);

            return $workflow->fresh();
        }

        // DEMO_AUTO path
        try {
            $this->gate->assertSafeForBrokerAction($session);
        } catch (ValidationException $e) {
            $workflow->rejection_code = 'SAFETY_GATE';
            $workflow->rejection_detail = collect($e->errors())->flatten()->implode('; ');
            $workflow->completed_at = now();
            $workflow->save();

            return $this->transition($workflow, AutomationWorkflowState::Rejected, 'SAFETY_GATE');
        }

        return $this->runRiskAndExecute($user, $session, $profile, $workflow);
    }

    private function runRiskAndExecute(
        User $user,
        AutomationSession $session,
        AutomationProfile $profile,
        AutomationWorkflow $workflow,
    ): AutomationWorkflow {
        $instrument = TradingInstrument::query()->where('symbol', $workflow->symbol)->first();
        if (! $instrument || ! $session->broker_account_id) {
            $workflow->rejection_code = 'INSTRUMENT_OR_ACCOUNT_MISSING';
            $workflow->completed_at = now();
            $workflow->save();

            return $this->transition($workflow, AutomationWorkflowState::Rejected, 'SETUP');
        }

        $side = str_contains((string) $workflow->direction, 'SELL') || $workflow->direction === 'SHORT'
            ? OrderDirection::Sell
            : OrderDirection::Buy;

        $this->transition($workflow, AutomationWorkflowState::RiskPending, 'RISK');

        $intent = TradeIntent::query()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'broker_account_id' => $session->broker_account_id,
            'trading_instrument_id' => $instrument->id,
            'origin' => TradeOrigin::Automation,
            'side' => $side,
            'order_type' => OrderType::Market,
            'status' => TradeIntentStatus::PendingRisk,
            'environment' => TradingEnvironment::Demo,
            'requested_volume' => '0.01',
            'volume' => '0.01',
            'stop_loss' => $side === OrderDirection::Buy ? '1.09000' : '1.12000',
            'take_profit' => $side === OrderDirection::Buy ? '1.12000' : '1.09000',
            'idempotency_key' => 'atm-'.$workflow->public_id,
            'metadata' => [
                'automation_workflow_id' => $workflow->public_id,
                'automation_session_id' => $session->public_id,
                'auto_demo' => true,
                'strategy_key' => $workflow->strategy_key,
                'strategy_version' => $workflow->strategy_version,
            ],
        ]);

        // Fresh risk — never reuse stale RiskDecision
        $decision = $this->risk->evaluate($intent->fresh(['instrument', 'brokerAccount', 'brokerAccount.riskProfile', 'brokerAccount.snapshots']));
        $workflow->trade_intent_id = $intent->id;
        $workflow->risk_decision_id = $decision->id;
        $workflow->save();

        if ($decision->status !== RiskDecisionStatus::Approved) {
            $workflow->rejection_code = 'RISK_REJECTED';
            $workflow->rejection_stage = 'RISK';
            $workflow->rejection_detail = $decision->status->value;
            $workflow->completed_at = now();
            $workflow->save();
            $this->transition($workflow, AutomationWorkflowState::RiskRejected, 'RISK');

            return $workflow->fresh();
        }

        $this->transition($workflow, AutomationWorkflowState::RiskApproved, 'RISK_OK');
        $intent->refresh();

        // Automation confirmation → Phase 10 submit (sole order_send path)
        $this->transition($workflow, AutomationWorkflowState::ExecutionPending, 'EXEC');
        $request = Request::create('/api/v1/automation/internal-execute', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            $autoConfirm = $this->confirmations->createAutomationConfirmation($intent->fresh(['instrument', 'brokerAccount', 'riskDecision']), $session, $request);
            $submit = $this->execution->submitDemo(
                $intent->fresh(['instrument', 'brokerAccount', 'riskDecision']),
                $autoConfirm['confirmation'],
                $autoConfirm['confirm_token'],
                'atm-exec-'.$workflow->public_id,
                $request,
            );
            $workflow->execution_command_id = $submit['command']->id ?? null;
            $workflow->broker_touched = true;
            $outcome = strtoupper((string) ($submit['result']->outcome ?? $submit['command']->status?->value ?? 'UNKNOWN'));
            if (str_contains($outcome, 'UNKNOWN') || ($submit['result'] === null && ! ($submit['replayed'] ?? false))) {
                $this->transition($workflow, AutomationWorkflowState::ExecutionUnknown, 'UNKNOWN_RECONCILE');
                $this->events->record($user, $session, 'EXECUTION_UNKNOWN', [
                    'workflow' => $workflow->public_id,
                    'note' => 'No blind retry — reconcile required.',
                ], 'ERROR', $workflow->id);
            } elseif (str_contains($outcome, 'FILL') || str_contains($outcome, 'FILLED') || str_contains($outcome, 'SUCCESS')) {
                $this->transition($workflow, AutomationWorkflowState::ExecutionFilled, 'FILLED');
                $this->transition($workflow, AutomationWorkflowState::ManagementActive, 'HANDOFF_PHASE_11');
                $this->events->record($user, $session, 'HANDOFF_TRADE_MANAGEMENT', [
                    'workflow' => $workflow->public_id,
                    'note' => 'Orchestrator does not trail/close directly — Phase 11 owns management.',
                ], 'INFO', $workflow->id);
            } else {
                $workflow->rejection_code = 'EXECUTION_'.$outcome;
                $workflow->completed_at = now();
                $workflow->save();
                $this->transition($workflow, AutomationWorkflowState::ExecutionRejected, $outcome);
            }
        } catch (\Throwable $e) {
            $workflow->rejection_code = 'EXECUTION_ERROR';
            $workflow->rejection_detail = $e->getMessage();
            $workflow->completed_at = now();
            $workflow->save();
            $this->transition($workflow, AutomationWorkflowState::ExecutionRejected, 'ERROR');
            $this->events->record($user, $session, 'EXECUTION_ERROR', [
                'error' => $e->getMessage(),
                'no_blind_retry' => true,
            ], 'ERROR', $workflow->id);
        }

        return $workflow->fresh();
    }

    private function transition(AutomationWorkflow $workflow, AutomationWorkflowState $state, string $note): AutomationWorkflow
    {
        $workflow->state = $state;
        $this->appendTrace($workflow, $state->value, $note);
        $workflow->save();

        return $workflow->fresh();
    }

    private function appendTrace(AutomationWorkflow $workflow, string $state, ?string $note = null): void
    {
        $trace = $workflow->trace ?? [];
        $trace[] = [
            'at' => now()->toIso8601String(),
            'state' => $state,
            'note' => $note,
        ];
        $workflow->trace = $trace;
    }
}
