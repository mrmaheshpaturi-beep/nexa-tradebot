<?php

namespace App\Intelligence\Providers;

interface CalendarProviderInterface
{
    public function name(): string;

    /**
     * @return array{provider_status: string, events: list<array<string, mixed>>, is_fabricated: bool, disclaimer: string}
     */
    public function fetch(?string $currency = null): array;
}
