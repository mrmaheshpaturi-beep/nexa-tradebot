<?php

namespace App\Observability\Providers;

use App\Models\Notification;
use App\Models\User;
use App\Observability\Contracts\NotificationProvider;

/**
 * In-app notification provider — always available; no paid SaaS required.
 */
class InAppNotificationProvider implements NotificationProvider
{
    public function name(): string
    {
        return 'IN_APP';
    }

    public function configured(): bool
    {
        return true;
    }

    public function notify(array $alert): array
    {
        $userId = $alert['user_id'] ?? User::query()->orderBy('id')->value('id');
        if (! $userId) {
            return ['status' => 'SKIPPED', 'provider' => $this->name(), 'detail' => 'no_user'];
        }

        Notification::query()->create([
            'user_id' => $userId,
            'type' => $alert['category'] ?? 'SYSTEM_ALERT',
            'category' => 'OPS',
            'severity' => $alert['severity'] ?? 'INFO',
            'title' => $alert['title'] ?? 'System alert',
            'message' => $alert['body'] ?? ($alert['title'] ?? 'System alert'),
            'data' => [
                'phase' => 15,
                'alert_public_id' => $alert['public_id'] ?? null,
                'fingerprint' => $alert['fingerprint'] ?? null,
            ],
            'is_read' => false,
        ]);

        return ['status' => 'DELIVERED', 'provider' => $this->name()];
    }
}
