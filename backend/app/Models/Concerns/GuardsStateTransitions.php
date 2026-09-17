<?php

namespace App\Models\Concerns;

use BackedEnum;
use DomainException;

trait GuardsStateTransitions
{
    public function transitionTo(BackedEnum $next): void
    {
        $current = $this->status;

        if (! $current instanceof BackedEnum || ! method_exists($current, 'canTransitionTo') || ! $current->canTransitionTo($next)) {
            throw new DomainException("Invalid status transition from {$current->value} to {$next->value}.");
        }

        $this->status = $next;
        $this->save();
    }
}
