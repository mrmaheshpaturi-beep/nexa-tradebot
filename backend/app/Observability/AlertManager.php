<?php

namespace App\Observability;

use App\Enums\AlertSeverity;
use App\Models\SystemAlert;
use App\Models\User;
use App\Observability\Contracts\NotificationProvider;
use App\Observability\Providers\InAppNotificationProvider;
use App\Observability\Providers\OptionalChannelNotificationProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Alert manager: severity, categories, dedup, cooldown, ACK.
 * NotificationProvider interface — paid providers optional.
 */
class AlertManager
{
    public const DEFAULT_COOLDOWN_SECONDS = 300;

    /** @var list<NotificationProvider> */
    private array $providers;

    public function __construct(
        private readonly StructuredLogger $logger,
        ?InAppNotificationProvider $inApp = null,
    ) {
        $this->providers = [
            $inApp ?? new InAppNotificationProvider,
            new OptionalChannelNotificationProvider('email'),
            new OptionalChannelNotificationProvider('telegram'),
        ];
    }

    public function raise(
        string $category,
        string $title,
        AlertSeverity|string $severity = AlertSeverity::Warning,
        ?string $body = null,
        array $payload = [],
        ?int $userId = null,
        int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
    ): SystemAlert {
        $severityValue = $severity instanceof AlertSeverity ? $severity->value : strtoupper($severity);
        $fingerprint = hash('sha256', strtoupper($category).'|'.$title.'|'.$severityValue);
        $existing = SystemAlert::query()
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', ['OPEN', 'ACKED'])
            ->where(function ($q) {
                $q->whereNull('cooldown_until')->orWhere('cooldown_until', '>', now());
            })
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->occurrence_count = ($existing->occurrence_count ?? 1) + 1;
            $existing->last_seen_at = now();
            $existing->save();
            $this->logger->info('AlertManager', 'Alert deduplicated', [
                'fingerprint' => $fingerprint,
                'public_id' => $existing->public_id,
            ]);

            return $existing;
        }

        $alert = SystemAlert::query()->create([
            'public_id' => (string) Str::uuid(),
            'fingerprint' => $fingerprint,
            'category' => strtoupper($category),
            'severity' => $severityValue,
            'title' => $title,
            'body' => $body,
            'payload' => array_merge($payload, [
                'phase' => 15,
                'order_send' => false,
            ]),
            'status' => 'OPEN',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
            'cooldown_until' => now()->addSeconds($cooldownSeconds),
        ]);

        $notifyPayload = $alert->toArray();
        if ($userId) {
            $notifyPayload['user_id'] = $userId;
        }

        foreach ($this->providers as $provider) {
            $result = $provider->notify($notifyPayload);
            $this->logger->info('AlertManager', 'Provider notify', $result);
        }

        return $alert;
    }

    public function acknowledge(string $publicId, User $user): SystemAlert
    {
        $alert = SystemAlert::query()->where('public_id', $publicId)->firstOrFail();
        $alert->status = 'ACKED';
        $alert->acked_at = now();
        $alert->acked_by = $user->id;
        $alert->save();

        return $alert;
    }

    public function resolve(string $publicId): SystemAlert
    {
        $alert = SystemAlert::query()->where('public_id', $publicId)->firstOrFail();
        $alert->status = 'RESOLVED';
        $alert->resolved_at = now();
        $alert->save();

        return $alert;
    }

    public function center(?string $status = null): Collection
    {
        $q = SystemAlert::query()->orderByDesc('last_seen_at');
        if ($status) {
            $q->where('status', strtoupper($status));
        }

        return $q->limit(100)->get();
    }

    /** @return list<array{name: string, configured: bool}> */
    public function providers(): array
    {
        return array_map(fn (NotificationProvider $p) => [
            'name' => $p->name(),
            'configured' => $p->configured(),
        ], $this->providers);
    }
}
