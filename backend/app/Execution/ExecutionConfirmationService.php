<?php

namespace App\Execution;

use App\Enums\ExecutionConfirmationStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\TradeIntentStatus;
use App\Enums\TradingEnvironment;
use App\Models\ExecutionConfirmation;
use App\Models\ExecutionEvent;
use App\Models\TradeIntent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step manual confirmation required before DEMO submit. Auto Demo remains OFF.
 */
class ExecutionConfirmationService
{
    public function __construct(
        private readonly DemoAccountVerifier $verifier,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{confirmation:ExecutionConfirmation,challenge_token:string,replayed:bool}
     */
    public function startStep1(TradeIntent $intent, string $idempotencyKey, Request $request): array
    {
        $user = $request->user();
        $this->assertDemoIntentReady($intent, $user);

        return DB::transaction(function () use ($intent, $idempotencyKey, $request, $user): array {
            $existing = ExecutionConfirmation::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return ['confirmation' => $existing, 'challenge_token' => '', 'replayed' => true];
            }

            $verification = $this->verifier->verify($intent->brokerAccount, true);
            $challenge = Str::random(48);
            $confirmation = ExecutionConfirmation::query()->create([
                'user_id' => $user->id,
                'trade_intent_id' => $intent->id,
                'broker_account_id' => $intent->broker_account_id,
                'environment' => TradingEnvironment::Demo,
                'status' => ExecutionConfirmationStatus::Initiated,
                'step' => 1,
                'challenge_token_hash' => hash('sha256', $challenge),
                'idempotency_key' => $idempotencyKey,
                'preview_payload' => [
                    'symbol' => $intent->instrument->symbol,
                    'side' => $intent->side->value,
                    'order_type' => $intent->order_type->value,
                    'volume' => (string) $intent->requested_volume,
                    'stop_loss' => $intent->stop_loss,
                    'take_profit' => $intent->take_profit,
                    'disclaimer' => 'DEMO EXECUTION — not live funds. Manual two-step confirmation required.',
                    'auto_demo' => false,
                ],
                'fresh_context' => ['verification' => $verification],
                'step1_at' => now(),
                'expires_at' => now()->addMinutes(10),
            ]);
            $confirmation->transitionTo(ExecutionConfirmationStatus::Step1Complete);
            $intent->update([
                'confirmation_status' => ExecutionConfirmationStatus::Step1Complete->value,
                'active_confirmation_id' => $confirmation->id,
            ]);
            $this->recordEvent($confirmation, 'CONFIRMATION_STEP1', $user->id);
            $this->audit->record('execution.confirmation.step1', $confirmation, [], [
                'intent' => $intent->public_id,
                'environment' => 'DEMO',
            ], $request);

            return ['confirmation' => $confirmation->fresh(), 'challenge_token' => $challenge, 'replayed' => false];
        });
    }

    /**
     * @return array{confirmation:ExecutionConfirmation,confirm_token:string}
     */
    public function completeStep2(ExecutionConfirmation $confirmation, string $challengeToken, Request $request): array
    {
        $user = $request->user();
        abort_unless($confirmation->user_id === $user->id, 404);

        return DB::transaction(function () use ($confirmation, $challengeToken, $request, $user): array {
            $confirmation = ExecutionConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->firstOrFail();
            if ($confirmation->status !== ExecutionConfirmationStatus::Step1Complete) {
                throw ValidationException::withMessages(['confirmation' => 'Confirmation is not awaiting step 2.']);
            }
            if ($confirmation->expires_at?->isPast()) {
                $confirmation->transitionTo(ExecutionConfirmationStatus::Expired);
                throw ValidationException::withMessages(['confirmation' => 'Confirmation challenge expired.']);
            }
            if (! hash_equals((string) $confirmation->challenge_token_hash, hash('sha256', $challengeToken))) {
                throw ValidationException::withMessages(['challenge_token' => 'Invalid confirmation challenge token.']);
            }

            $confirmToken = Str::random(48);
            $confirmation->forceFill([
                'confirm_token_hash' => hash('sha256', $confirmToken),
                'step' => 2,
                'step2_at' => now(),
            ])->save();
            $confirmation->transitionTo(ExecutionConfirmationStatus::Confirmed);
            $confirmation->tradeIntent->update([
                'confirmation_status' => ExecutionConfirmationStatus::Confirmed->value,
            ]);
            $this->recordEvent($confirmation, 'CONFIRMATION_STEP2', $user->id);
            $this->audit->record('execution.confirmation.step2', $confirmation, [], [
                'intent' => $confirmation->tradeIntent->public_id,
                'environment' => 'DEMO',
            ], $request);

            return ['confirmation' => $confirmation->fresh(), 'confirm_token' => $confirmToken];
        });
    }

    /**
     * Phase 14 automation confirmation — only when AUTO DEMO enabled and session is DEMO_AUTO.
     * Still goes through Phase 10 ExecutionEngine; does not call order_send.
     *
     * @return array{confirmation:ExecutionConfirmation,confirm_token:string}
     */
    public function createAutomationConfirmation(TradeIntent $intent, \App\Models\AutomationSession $session, Request $request): array
    {
        $user = $request->user();
        $this->assertDemoIntentReady($intent, $user);

        if ($session->mode->value !== 'DEMO_AUTO') {
            throw ValidationException::withMessages(['session' => 'Automation confirmation requires DEMO_AUTO mode.']);
        }
        if (app(\App\Services\SettingsService::class)->value('auto_demo_execution') !== true) {
            throw ValidationException::withMessages(['auto_demo_execution' => 'AUTO DEMO setting required.']);
        }

        return DB::transaction(function () use ($intent, $session, $request, $user): array {
            $verification = $this->verifier->verify($intent->brokerAccount, true);
            $confirmToken = Str::random(48);
            $confirmation = ExecutionConfirmation::query()->create([
                'user_id' => $user->id,
                'trade_intent_id' => $intent->id,
                'broker_account_id' => $intent->broker_account_id,
                'environment' => TradingEnvironment::Demo,
                'status' => ExecutionConfirmationStatus::Initiated,
                'step' => 1,
                'challenge_token_hash' => hash('sha256', Str::random(48)),
                'confirm_token_hash' => hash('sha256', $confirmToken),
                'idempotency_key' => 'atm-cnf-'.$intent->public_id,
                'preview_payload' => [
                    'symbol' => $intent->instrument->symbol,
                    'side' => $intent->side->value,
                    'order_type' => $intent->order_type->value,
                    'volume' => (string) $intent->requested_volume,
                    'stop_loss' => $intent->stop_loss,
                    'take_profit' => $intent->take_profit,
                    'disclaimer' => 'AUTO DEMO TRADING — not live funds. Orchestrated confirmation (not AUTO LIVE).',
                    'auto_demo' => true,
                    'automation_session_id' => $session->public_id,
                ],
                'fresh_context' => [
                    'verification' => $verification,
                    'automation' => true,
                    'session' => $session->public_id,
                ],
                'step1_at' => now(),
                'step2_at' => now(),
                'expires_at' => now()->addMinutes(5),
            ]);
            $confirmation->transitionTo(ExecutionConfirmationStatus::Step1Complete);
            $confirmation->transitionTo(ExecutionConfirmationStatus::Confirmed);
            $intent->update([
                'confirmation_status' => ExecutionConfirmationStatus::Confirmed->value,
                'active_confirmation_id' => $confirmation->id,
            ]);
            $this->recordEvent($confirmation, 'CONFIRMATION_AUTOMATION', $user->id);
            $this->audit->record('execution.confirmation.automation', $confirmation, [], [
                'intent' => $intent->public_id,
                'environment' => 'DEMO',
                'auto_demo' => true,
                'session' => $session->public_id,
            ], $request);

            return ['confirmation' => $confirmation->fresh(), 'confirm_token' => $confirmToken];
        });
    }

    public function assertConsumable(ExecutionConfirmation $confirmation, string $confirmToken): void
    {
        if ($confirmation->status !== ExecutionConfirmationStatus::Confirmed) {
            throw ValidationException::withMessages(['confirmation' => 'Two-step DEMO confirmation is not complete.']);
        }
        if ($confirmation->expires_at?->isPast()) {
            throw ValidationException::withMessages(['confirmation' => 'DEMO confirmation expired.']);
        }
        if (! hash_equals((string) $confirmation->confirm_token_hash, hash('sha256', $confirmToken))) {
            throw ValidationException::withMessages(['confirm_token' => 'Invalid DEMO confirm token.']);
        }
    }

    public function consume(ExecutionConfirmation $confirmation): ExecutionConfirmation
    {
        if ($confirmation->status === ExecutionConfirmationStatus::Confirmed) {
            $confirmation->transitionTo(ExecutionConfirmationStatus::Consumed);
        }

        return $confirmation->fresh();
    }

    private function assertDemoIntentReady(TradeIntent $intent, User $user): void
    {
        abort_unless($intent->user_id === $user->id, 404);
        if ($intent->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages(['intent' => 'Confirmation applies only to DEMO intents.']);
        }
        if ($intent->status !== TradeIntentStatus::RiskApproved
            || $intent->riskDecision?->status !== RiskDecisionStatus::Approved) {
            throw ValidationException::withMessages(['intent' => 'Only risk-approved DEMO intents can enter confirmation.']);
        }
    }

    private function recordEvent(ExecutionConfirmation $confirmation, string $type, int $userId): void
    {
        ExecutionEvent::query()->create([
            'trade_intent_id' => $confirmation->trade_intent_id,
            'user_id' => $userId,
            'environment' => TradingEnvironment::Demo,
            'event_type' => $type,
            'severity' => 'INFO',
            'payload' => [
                'confirmation_public_id' => $confirmation->public_id,
                'step' => $confirmation->step,
                'auto_demo' => false,
            ],
            'occurred_at' => now(),
        ]);
    }
}
