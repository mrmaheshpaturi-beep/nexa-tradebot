<?php

namespace App\Intelligence\Providers;

/**
 * Deterministic mock calendar — events are ALWAYS labeled fabricated/mock.
 * Never presented as real economic calendar data.
 */
class MockCalendarProvider implements CalendarProviderInterface
{
    public function name(): string
    {
        return 'MOCK';
    }

    public function fetch(?string $currency = null): array
    {
        $ccy = $currency ?: 'USD';
        $base = strtotime('2025-06-15T12:00:00Z');

        return [
            'provider_status' => 'OK',
            'is_fabricated' => true,
            'disclaimer' => 'MOCK calendar — fabricated demo events only. Not real economic data.',
            'events' => [
                [
                    'title' => "Mock {$ccy} Interest Decision",
                    'currency' => $ccy,
                    'impact' => 'HIGH',
                    'event_at' => date('c', $base),
                    'is_fabricated' => true,
                ],
                [
                    'title' => "Mock {$ccy} CPI Preview",
                    'currency' => $ccy,
                    'impact' => 'MEDIUM',
                    'event_at' => date('c', $base + 86400),
                    'is_fabricated' => true,
                ],
            ],
        ];
    }
}
