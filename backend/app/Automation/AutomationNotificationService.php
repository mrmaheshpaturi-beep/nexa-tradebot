<?php

namespace App\Automation;

use App\Models\AutomationNotification;
use App\Models\AutomationSession;
use App\Models\User;

/** In-app notifications foundation — email/Telegram not required for Phase 14 PASS. */
class AutomationNotificationService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function notify(
        User $user,
        ?AutomationSession $session,
        string $title,
        ?string $body = null,
        string $severity = 'INFO',
        array $payload = [],
    ): AutomationNotification {
        return AutomationNotification::query()->create([
            'user_id' => $user->id,
            'automation_session_id' => $session?->id,
            'channel' => 'IN_APP',
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'read' => false,
            'payload' => $payload,
        ]);
    }
}
