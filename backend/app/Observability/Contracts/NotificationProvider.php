<?php

namespace App\Observability\Contracts;

interface NotificationProvider
{
    public function name(): string;

    public function configured(): bool;

    /**
     * Deliver or record an alert. Paid providers are optional — may no-op when unconfigured.
     *
     * @param  array<string, mixed>  $alert
     * @return array{status: string, provider: string, detail?: string}
     */
    public function notify(array $alert): array;
}
