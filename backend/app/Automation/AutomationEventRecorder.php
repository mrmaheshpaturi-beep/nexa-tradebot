<?php

namespace App\Automation;

use App\Models\AutomationEvent;
use App\Models\AutomationSession;
use App\Models\User;

class AutomationEventRecorder
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(
        User $user,
        ?AutomationSession $session,
        string $eventType,
        array $payload = [],
        string $severity = 'INFO',
        ?int $workflowId = null,
    ): AutomationEvent {
        return AutomationEvent::query()->create([
            'user_id' => $user->id,
            'automation_session_id' => $session?->id,
            'automation_workflow_id' => $workflowId,
            'event_type' => $eventType,
            'severity' => $severity,
            'immutable' => true,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
