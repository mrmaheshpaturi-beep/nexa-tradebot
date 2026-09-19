<?php

namespace App\Observability;

use App\Enums\ValidationSessionMode;
use App\Models\User;
use App\Models\ValidationObservation;
use App\Models\ValidationSession;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Str;

/**
 * Forward validation distinct from backtest.
 * Funnel + rejection analysis + missed-opportunity research-only.
 * DRY_RUN vs DEMO_AUTO shadow + AI shadow + calibration.
 */
class ForwardValidationService
{
    public function __construct(private readonly StructuredLogger $logger) {}

    public function start(User $user, ValidationSessionMode|string $mode, array $config = []): ValidationSession
    {
        $modeEnum = $mode instanceof ValidationSessionMode ? $mode : ValidationSessionMode::from(strtoupper($mode));
        $evidence = match ($modeEnum) {
            ValidationSessionMode::DemoForward, ValidationSessionMode::DemoAutoShadow => 'DEMO',
            ValidationSessionMode::DryRunShadow => 'DRY_RUN',
            ValidationSessionMode::AiShadow => 'DEMO',
        };

        ObservabilitySafety::assertEvidenceLabel($evidence);

        $session = ValidationSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'mode' => $modeEnum->value,
            'evidence_label' => $evidence,
            'status' => 'ACTIVE',
            'config' => array_merge($config, [
                'is_forward_test' => true,
                'is_backtest' => false,
                'distinct_from_backtest' => true,
            ]),
            'funnel' => [
                'scanned' => 0,
                'signaled' => 0,
                'candidate' => 0,
                'intelligence' => 0,
                'qualified' => 0,
                'risk_approved' => 0,
                'executed_or_shadow' => 0,
                'rejected' => 0,
                'missed_opportunity_research' => 0,
            ],
            'rejection_analysis' => [],
            'calibration' => ['ai_shadow_agreement' => null, 'samples' => 0],
            'is_backtest' => false,
            'started_at' => now(),
        ]);

        $this->logger->info('ForwardValidationService', 'Validation session started', [
            'public_id' => $session->public_id,
            'mode' => $modeEnum->value,
            'evidence_label' => $evidence,
        ]);

        return $session;
    }

    public function observe(
        ValidationSession $session,
        string $stage,
        string $outcome,
        array $payload = [],
        bool $researchOnly = false,
        ?string $symbol = null,
        ?string $strategyKey = null,
    ): ValidationObservation {
        if ($session->is_backtest) {
            throw new \RuntimeException('Forward validation session must not be marked as backtest.');
        }

        $obs = ValidationObservation::query()->create([
            'public_id' => (string) Str::uuid(),
            'validation_session_id' => $session->id,
            'stage' => strtoupper($stage),
            'outcome' => strtoupper($outcome),
            'symbol' => $symbol,
            'strategy_key' => $strategyKey,
            'payload' => $payload,
            'research_only' => $researchOnly,
            'observed_at' => now(),
        ]);

        $funnel = $session->funnel ?? [];
        $stageKey = strtolower($stage);
        if (array_key_exists($stageKey, $funnel)) {
            $funnel[$stageKey] = (int) $funnel[$stageKey] + 1;
        }
        if (str_starts_with(strtoupper($outcome), 'REJECT')) {
            $funnel['rejected'] = (int) ($funnel['rejected'] ?? 0) + 1;
            $rej = $session->rejection_analysis ?? [];
            $reason = $payload['reason'] ?? $outcome;
            $rej[$reason] = (int) ($rej[$reason] ?? 0) + 1;
            $session->rejection_analysis = $rej;
        }
        if ($researchOnly || strtoupper($outcome) === 'MISSED_OPPORTUNITY') {
            $funnel['missed_opportunity_research'] = (int) ($funnel['missed_opportunity_research'] ?? 0) + 1;
        }
        $session->funnel = $funnel;
        $session->save();

        return $obs;
    }

    public function calibrate(ValidationSession $session, float $agreement, int $samples): ValidationSession
    {
        $session->calibration = [
            'ai_shadow_agreement' => $agreement,
            'samples' => $samples,
            'insufficient_sample' => $samples < MetricsRegistry::MIN_SAMPLE_WARNING,
            'note' => 'Calibration is research/advisory — AI never gains trade authority',
        ];
        $session->save();

        return $session;
    }

    public function end(ValidationSession $session): ValidationSession
    {
        $session->status = 'ENDED';
        $session->ended_at = now();
        $session->save();

        return $session;
    }

    public function labSummary(): array
    {
        $sessions = ValidationSession::query()->orderByDesc('id')->limit(50)->get();

        return [
            'phase' => ObservabilitySafety::PHASE,
            'forward_testing_distinct_from_backtest' => true,
            'modes' => array_map(fn ($c) => $c->value, ValidationSessionMode::cases()),
            'sessions' => $sessions->map(fn (ValidationSession $s) => [
                'public_id' => $s->public_id,
                'mode' => $s->mode,
                'evidence_label' => $s->evidence_label,
                'status' => $s->status,
                'is_backtest' => $s->is_backtest,
                'funnel' => $s->funnel,
                'calibration' => $s->calibration,
                'started_at' => $s->started_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
