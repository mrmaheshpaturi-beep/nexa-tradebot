<?php

namespace App\Intelligence\Providers;

interface AIProviderInterface
{
    public function name(): string;

    public function modelVersion(): string;

    /**
     * @param  array<string, mixed>  $structuredInput
     * @return array{status: string, structured_output: ?array, tokens_in: int, tokens_out: int, meta: array}
     */
    public function analyze(array $structuredInput, string $promptVersion): array;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{status: string, reply: string, tokens_in: int, tokens_out: int, meta: array}
     */
    public function chat(array $messages, string $promptVersion): array;
}
