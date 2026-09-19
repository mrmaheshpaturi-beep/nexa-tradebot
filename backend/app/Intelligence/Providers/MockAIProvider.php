<?php

namespace App\Intelligence\Providers;

/**
 * Deterministic Mock AI — required for CI. No paid API calls.
 * Read-only structured analysis and chat. Zero mutation tools.
 */
class MockAIProvider implements AIProviderInterface
{
    public function name(): string
    {
        return 'MOCK';
    }

    public function modelVersion(): string
    {
        return 'mock-ai/v1';
    }

    public function analyze(array $structuredInput, string $promptVersion): array
    {
        $symbol = (string) ($structuredInput['symbol'] ?? 'UNKNOWN');
        $bias = (string) ($structuredInput['technical']['bias'] ?? 'NEUTRAL');
        $score = (float) ($structuredInput['ensemble']['confluence_score'] ?? 0);
        $hash = substr(hash('sha256', json_encode($structuredInput, JSON_THROW_ON_ERROR)), 0, 12);

        $summary = sprintf(
            'ADVISORY mock analysis for %s: technical bias %s, confluence %.1f (hash %s). Shadow/advisory only — no execution.',
            $symbol,
            $bias,
            $score,
            $hash
        );

        return [
            'status' => 'COMPLETED',
            'structured_output' => [
                'summary' => $summary,
                'bias' => $bias,
                'advisory_stance' => $score >= 55 ? 'WATCH' : 'STAND_ASIDE',
                'confidence_raw' => round(min(0.85, max(0.15, $score / 100)), 4),
                'key_points' => [
                    'Deterministic mock provider — not a live model.',
                    'No mutation tools; cannot place or manage orders.',
                    'LIVE remains HARD_BLOCKED.',
                ],
                'risks' => [
                    'Advisory output is not a trade instruction.',
                    'Market conditions can invalidate the stance quickly.',
                ],
                'prompt_version' => $promptVersion,
                'model_version' => $this->modelVersion(),
            ],
            'tokens_in' => 120,
            'tokens_out' => 80,
            'meta' => ['provider' => 'MOCK', 'deterministic' => true],
        ];
    }

    public function chat(array $messages, string $promptVersion): array
    {
        $last = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user') {
                $last = (string) ($m['content'] ?? '');
                break;
            }
        }
        $reply = 'ADVISORY explanation only (mock AI). I can clarify signals, confluence, and market quality. '
            .'I cannot execute trades, call order_send, mutate risk/settings/strategies, or enable LIVE. '
            .'Prompt='.$promptVersion.'. You asked: '.mb_substr($last, 0, 200);

        return [
            'status' => 'COMPLETED',
            'reply' => $reply,
            'tokens_in' => 60,
            'tokens_out' => 90,
            'meta' => ['provider' => 'MOCK', 'read_only' => true, 'mutation_tools' => false],
        ];
    }
}
