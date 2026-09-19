<?php

namespace App\Governance\Support;

use App\Enums\StrategyLifecycleState;
use Illuminate\Validation\ValidationException;

final class LifecycleGuard
{
    public static function assertTransition(StrategyLifecycleState|string $from, StrategyLifecycleState|string $to): void
    {
        $fromState = $from instanceof StrategyLifecycleState ? $from : StrategyLifecycleState::from(strtoupper((string) $from));
        $toState = $to instanceof StrategyLifecycleState ? $to : StrategyLifecycleState::from(strtoupper((string) $to));

        if (! $fromState->canTransitionTo($toState)) {
            throw ValidationException::withMessages([
                'lifecycle' => "Illegal lifecycle jump {$fromState->value} → {$toState->value}. Allowed: ".
                    implode(', ', array_map(fn (StrategyLifecycleState $s) => $s->value, $fromState->allowedNext())) ?: '(none)',
            ]);
        }
    }
}
