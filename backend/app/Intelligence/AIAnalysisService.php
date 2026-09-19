<?php

namespace App\Intelligence;

use App\Intelligence\Providers\AIProviderInterface;
use App\Intelligence\Providers\ProviderResolver;
use App\Intelligence\Support\IntelligenceSafety;
use App\Models\IntelligenceAiAnalysis;
use App\Models\IntelligenceChatMessage;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Provider-agnostic AI analysis — validated structured output, input hashes,
 * prompt/model versions, injection protection, read-only chat.
 * CI must use MockAIProvider (no paid calls).
 */
class AIAnalysisService
{
    private readonly AIProviderInterface $provider;

    public function __construct(
        private readonly UsageMeter $usage = new UsageMeter,
        private readonly AuditService $audit = new AuditService,
        private readonly ProviderResolver $resolver = new ProviderResolver,
        ?AIProviderInterface $provider = null,
    ) {
        $this->provider = $provider ?? $this->resolver->ai();
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function analyze(User $user, array $input, ?int $assessmentId = null, ?Request $request = null): IntelligenceAiAnalysis
    {
        if (! $this->usage->consume($user, 'ai_calls', 1)) {
            return IntelligenceAiAnalysis::query()->create([
                'user_id' => $user->id,
                'intelligence_assessment_id' => $assessmentId,
                'provider' => $this->provider->name(),
                'model_version' => $this->provider->modelVersion(),
                'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
                'input_hash' => $this->hashInput($input),
                'status' => 'BUDGET_EXCEEDED',
                'structured_output' => null,
                'mutation_tools_available' => false,
                'analyzed_at' => now(),
            ]);
        }

        $serialized = json_encode($input, JSON_THROW_ON_ERROR);
        if (IntelligenceSafety::detectInjection($serialized)) {
            $row = IntelligenceAiAnalysis::query()->create([
                'user_id' => $user->id,
                'intelligence_assessment_id' => $assessmentId,
                'provider' => $this->provider->name(),
                'model_version' => $this->provider->modelVersion(),
                'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
                'input_hash' => $this->hashInput($input),
                'status' => 'FAILED',
                'injection_blocked' => true,
                'validation_errors' => ['injection' => 'Blocked potentially injurious prompt content'],
                'mutation_tools_available' => false,
                'analyzed_at' => now(),
            ]);
            $this->audit->record('intelligence.ai_injection_blocked', $row, [], [], $request);

            return $row;
        }

        $result = $this->provider->analyze($input, IntelligenceSafety::PROMPT_VERSION);
        $output = $result['structured_output'] ?? null;
        $errors = $this->validateStructured($output);

        $row = IntelligenceAiAnalysis::query()->create([
            'user_id' => $user->id,
            'intelligence_assessment_id' => $assessmentId,
            'provider' => $this->provider->name(),
            'model_version' => $this->provider->modelVersion(),
            'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
            'input_hash' => $this->hashInput($input),
            'output_hash' => $output ? hash('sha256', json_encode($output, JSON_THROW_ON_ERROR)) : null,
            'status' => $errors === [] ? ($result['status'] ?? 'COMPLETED') : 'FAILED',
            'structured_output' => $errors === [] ? $output : null,
            'raw_meta' => $result['meta'] ?? [],
            'validation_errors' => $errors === [] ? null : $errors,
            'injection_blocked' => false,
            'mutation_tools_available' => false,
            'tokens_in' => (int) ($result['tokens_in'] ?? 0),
            'tokens_out' => (int) ($result['tokens_out'] ?? 0),
            'analyzed_at' => now(),
        ]);

        $this->audit->record('intelligence.ai_analyzed', $row, [], [
            'provider' => $row->provider,
            'status' => $row->status,
            'input_hash' => $row->input_hash,
        ], $request);

        return $row;
    }

    /**
     * Read-only explanation chat — no mutation tools ever.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{analysis: IntelligenceAiAnalysis, message: IntelligenceChatMessage}
     */
    public function chat(User $user, array $messages, ?Request $request = null): array
    {
        foreach ($messages as $m) {
            $content = (string) ($m['content'] ?? '');
            if (IntelligenceSafety::detectInjection($content)) {
                $analysis = IntelligenceAiAnalysis::query()->create([
                    'user_id' => $user->id,
                    'provider' => $this->provider->name(),
                    'model_version' => $this->provider->modelVersion(),
                    'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
                    'input_hash' => hash('sha256', json_encode($messages, JSON_THROW_ON_ERROR)),
                    'status' => 'FAILED',
                    'injection_blocked' => true,
                    'validation_errors' => ['injection' => 'Chat injection blocked'],
                    'mutation_tools_available' => false,
                    'analyzed_at' => now(),
                ]);
                $msg = IntelligenceChatMessage::query()->create([
                    'user_id' => $user->id,
                    'intelligence_ai_analysis_id' => $analysis->id,
                    'role' => 'assistant',
                    'content' => 'Request blocked: injection or mutation intent detected. Chat is read-only advisory.',
                    'read_only' => true,
                    'meta' => ['blocked' => true],
                ]);
                $this->audit->record('intelligence.chat_injection_blocked', $analysis, [], [], $request);

                return ['analysis' => $analysis, 'message' => $msg];
            }
        }

        if (! $this->usage->consume($user, 'ai_calls', 1)) {
            $analysis = IntelligenceAiAnalysis::query()->create([
                'user_id' => $user->id,
                'provider' => $this->provider->name(),
                'model_version' => $this->provider->modelVersion(),
                'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
                'input_hash' => hash('sha256', json_encode($messages, JSON_THROW_ON_ERROR)),
                'status' => 'BUDGET_EXCEEDED',
                'mutation_tools_available' => false,
                'analyzed_at' => now(),
            ]);
            $msg = IntelligenceChatMessage::query()->create([
                'user_id' => $user->id,
                'intelligence_ai_analysis_id' => $analysis->id,
                'role' => 'assistant',
                'content' => 'AI budget exceeded for this period. Advisory chat unavailable.',
                'read_only' => true,
            ]);

            return ['analysis' => $analysis, 'message' => $msg];
        }

        foreach ($messages as $m) {
            IntelligenceChatMessage::query()->create([
                'user_id' => $user->id,
                'role' => (string) ($m['role'] ?? 'user'),
                'content' => (string) ($m['content'] ?? ''),
                'read_only' => true,
            ]);
        }

        $result = $this->provider->chat($messages, IntelligenceSafety::PROMPT_VERSION);
        $analysis = IntelligenceAiAnalysis::query()->create([
            'user_id' => $user->id,
            'provider' => $this->provider->name(),
            'model_version' => $this->provider->modelVersion(),
            'prompt_version' => IntelligenceSafety::PROMPT_VERSION,
            'input_hash' => hash('sha256', json_encode($messages, JSON_THROW_ON_ERROR)),
            'output_hash' => hash('sha256', (string) ($result['reply'] ?? '')),
            'status' => $result['status'] ?? 'COMPLETED',
            'structured_output' => [
                'type' => 'chat',
                'reply' => $result['reply'] ?? '',
                'read_only' => true,
                'mutation_tools_available' => false,
            ],
            'raw_meta' => $result['meta'] ?? [],
            'mutation_tools_available' => false,
            'tokens_in' => (int) ($result['tokens_in'] ?? 0),
            'tokens_out' => (int) ($result['tokens_out'] ?? 0),
            'analyzed_at' => now(),
        ]);

        $msg = IntelligenceChatMessage::query()->create([
            'user_id' => $user->id,
            'intelligence_ai_analysis_id' => $analysis->id,
            'role' => 'assistant',
            'content' => (string) ($result['reply'] ?? ''),
            'read_only' => true,
            'meta' => ['provider' => $this->provider->name()],
        ]);

        $this->audit->record('intelligence.chat', $analysis, [], ['read_only' => true], $request);

        return ['analysis' => $analysis, 'message' => $msg];
    }

    /** @param  array<string, mixed>  $input */
    private function hashInput(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function validateStructured(mixed $output): array
    {
        if (! is_array($output)) {
            return ['structured_output' => 'Must be an object'];
        }

        $v = Validator::make($output, [
            'summary' => 'required|string|max:2000',
            'bias' => 'required|string|in:BULLISH,BEARISH,NEUTRAL',
            'advisory_stance' => 'required|string|max:64',
            'confidence_raw' => 'required|numeric|min:0|max:1',
            'key_points' => 'required|array|min:1',
            'key_points.*' => 'string|max:500',
            'risks' => 'required|array|min:1',
            'prompt_version' => 'required|string',
            'model_version' => 'required|string',
        ]);

        if ($v->fails()) {
            return $v->errors()->toArray();
        }

        // Refuse any mutation tool claims in output
        $blob = json_encode($output, JSON_THROW_ON_ERROR);
        foreach (IntelligenceSafety::FORBIDDEN_TOOL_NAMES as $tool) {
            if (str_contains(strtolower($blob), strtolower($tool).'(')) {
                return ['tools' => "Forbidden tool reference: {$tool}"];
            }
        }

        return [];
    }
}
