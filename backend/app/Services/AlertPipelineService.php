<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\ScannerAlertEvent;
use App\Models\SignalCandidate;
use App\Models\ScannerRun;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Alert pipeline FOUNDATION — records events and optional in-app notifications.
 * No email/SMS delivery in Phase 8. Never triggers broker actions.
 */
class AlertPipelineService
{
    public function emit(
        User $user,
        string $eventType,
        string $title,
        ?string $body = null,
        string $severity = 'INFO',
        string $channel = 'IN_APP',
        ?SignalCandidate $candidate = null,
        ?ScannerRun $run = null,
        array $payload = [],
        bool $createNotification = true,
    ): ScannerAlertEvent {
        $event = ScannerAlertEvent::query()->create([
            'user_id' => $user->id,
            'signal_candidate_id' => $candidate?->id,
            'scanner_run_id' => $run?->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'channel' => $channel,
            'title' => $title,
            'body' => $body,
            'payload' => array_merge($payload, [
                'phase' => 8,
                'execution' => [
                    'order_send' => false,
                    'broker_routing' => false,
                ],
            ]),
            'delivered' => false,
        ]);

        if ($createNotification && $channel === 'IN_APP') {
            $prefs = $user->preference;
            $enabled = $prefs?->notifications_enabled ?? true;
            if ($enabled) {
                Notification::query()->create([
                    'user_id' => $user->id,
                    'type' => $eventType,
                    'category' => 'SCANNER',
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $body ?? $title,
                    'data' => [
                        'alert_event_id' => $event->id,
                        'candidate_public_id' => $candidate?->public_id,
                        'scanner_run_id' => $run?->id,
                        'phase' => 8,
                    ],
                    'is_read' => false,
                ]);
                $event->delivered = true;
                $event->delivered_at = Carbon::now('UTC');
                $event->save();
            }
        }

        // Future channel hook placeholder (email/SMS/webhook) — recorded only.
        if ($channel === 'HOOK') {
            $event->payload = array_merge($event->payload ?? [], [
                'hook_status' => 'RECORDED_NOT_DISPATCHED',
            ]);
            $event->save();
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return [
            'service' => 'ALERT_PIPELINE',
            'phase' => 8,
            'channels' => ['IN_APP', 'HOOK'],
            'email' => 'NOT_IMPLEMENTED',
            'sms' => 'NOT_IMPLEMENTED',
            'broker_hooks' => false,
            'status' => 'FOUNDATION_READY',
        ];
    }
}
