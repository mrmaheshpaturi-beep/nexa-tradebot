<?php

namespace App\Observability;

use App\Models\SystemErrorRecord;
use App\Observability\Support\ObservabilitySafety;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured logging with secret redaction + SystemErrorRecord persistence.
 */
class StructuredLogger
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function info(string $component, string $message, array $context = []): void
    {
        $this->write('info', $component, $message, $context);
    }

    public function warning(string $component, string $message, array $context = []): void
    {
        $this->write('warning', $component, $message, $context);
    }

    public function error(string $component, string $message, array $context = [], ?string $code = null): SystemErrorRecord
    {
        $this->write('error', $component, $message, $context);

        return SystemErrorRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'correlation_id' => CorrelationId::current(),
            'component' => $component,
            'severity' => 'ERROR',
            'code' => $code,
            'message' => $message,
            'context' => $this->redactor->redact($context),
            'redacted' => true,
            'occurred_at' => now(),
        ]);
    }

    private function write(string $level, string $component, string $message, array $context): void
    {
        Log::{$level}($message, $this->redactor->redact(array_merge($context, [
            'phase' => ObservabilitySafety::PHASE,
            'component' => $component,
            'correlation_id' => CorrelationId::current(),
            'order_send' => false,
        ])));
    }
}
