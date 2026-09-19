<?php

namespace App\Intelligence\Providers;

class UnavailableCalendarProvider implements CalendarProviderInterface
{
    public function name(): string
    {
        return 'UNAVAILABLE';
    }

    public function fetch(?string $currency = null): array
    {
        return [
            'provider_status' => 'UNAVAILABLE',
            'is_fabricated' => false,
            'disclaimer' => 'Calendar provider UNAVAILABLE — no events fabricated as real.',
            'events' => [],
        ];
    }
}
