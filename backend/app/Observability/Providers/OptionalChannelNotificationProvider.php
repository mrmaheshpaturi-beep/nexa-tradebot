<?php

namespace App\Observability\Providers;

use App\Observability\Contracts\NotificationProvider;

/**
 * Optional email/Telegram hook — records intent only when unconfigured.
 * Never required for Phase 15 PASS.
 */
class OptionalChannelNotificationProvider implements NotificationProvider
{
    public function __construct(private readonly string $channel) {}

    public function name(): string
    {
        return strtoupper($this->channel);
    }

    public function configured(): bool
    {
        return false;
    }

    public function notify(array $alert): array
    {
        return [
            'status' => 'RECORDED_NOT_DISPATCHED',
            'provider' => $this->name(),
            'detail' => 'optional_provider_not_required_for_phase_15',
        ];
    }
}
