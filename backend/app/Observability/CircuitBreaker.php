<?php

namespace App\Observability;

use App\Enums\CircuitBreakerState;
use App\Models\CircuitBreakerRecord;
use Illuminate\Support\Str;

/**
 * Circuit breaker for broker / AI / news dependencies.
 * CLOSED → OPEN → HALF_OPEN.
 */
class CircuitBreaker
{
    public function __construct(private readonly StructuredLogger $logger) {}

    public function ensure(string $name, int $threshold = 5, int $cooldownSeconds = 60): CircuitBreakerRecord
    {
        return CircuitBreakerRecord::query()->firstOrCreate(
            ['name' => $name],
            [
                'public_id' => (string) Str::uuid(),
                'state' => CircuitBreakerState::Closed->value,
                'failure_count' => 0,
                'success_count' => 0,
                'threshold' => $threshold,
                'cooldown_seconds' => $cooldownSeconds,
                'metadata' => ['phase' => 15],
            ]
        );
    }

    public function allow(string $name): bool
    {
        $cb = $this->ensure($name);
        $this->maybeTransition($cb);

        return $cb->fresh()->state !== CircuitBreakerState::Open->value;
    }

    public function recordSuccess(string $name): CircuitBreakerRecord
    {
        $cb = $this->ensure($name);
        $cb->success_count++;
        if ($cb->state === CircuitBreakerState::HalfOpen->value) {
            $cb->state = CircuitBreakerState::Closed->value;
            $cb->failure_count = 0;
            $cb->opened_at = null;
            $cb->half_open_at = null;
        }
        $cb->save();

        return $cb;
    }

    public function recordFailure(string $name): CircuitBreakerRecord
    {
        $cb = $this->ensure($name);
        $cb->failure_count++;
        if ($cb->failure_count >= $cb->threshold) {
            $cb->state = CircuitBreakerState::Open->value;
            $cb->opened_at = now();
            $this->logger->warning('CircuitBreaker', "Opened circuit {$name}", [
                'failure_count' => $cb->failure_count,
            ]);
        }
        $cb->save();

        return $cb;
    }

    public function status(?string $name = null): array
    {
        $q = CircuitBreakerRecord::query()->orderBy('name');
        if ($name) {
            $q->where('name', $name);
        }

        return $q->get()->map(fn (CircuitBreakerRecord $c) => [
            'name' => $c->name,
            'state' => $c->state,
            'failure_count' => $c->failure_count,
            'success_count' => $c->success_count,
            'threshold' => $c->threshold,
        ])->all();
    }

    private function maybeTransition(CircuitBreakerRecord $cb): void
    {
        if ($cb->state === CircuitBreakerState::Open->value && $cb->opened_at) {
            if ($cb->opened_at->diffInSeconds(now()) >= $cb->cooldown_seconds) {
                $cb->state = CircuitBreakerState::HalfOpen->value;
                $cb->half_open_at = now();
                $cb->save();
            }
        }
    }
}
