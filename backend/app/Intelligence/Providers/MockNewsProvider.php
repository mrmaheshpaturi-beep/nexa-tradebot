<?php

namespace App\Intelligence\Providers;

/**
 * Deterministic mock news — headlines ALWAYS labeled fabricated.
 * Never presented as real news wires.
 */
class MockNewsProvider implements NewsProviderInterface
{
    public function name(): string
    {
        return 'MOCK';
    }

    public function fetch(?string $symbol = null): array
    {
        $sym = $symbol ?: 'EURUSD';

        return [
            'provider_status' => 'OK',
            'is_fabricated' => true,
            'disclaimer' => 'MOCK news — fabricated demo headlines only. Not real market news.',
            'items' => [
                [
                    'headline' => "[MOCK] {$sym} session tone remains range-bound in demo feed",
                    'source_label' => 'Nexa Mock Wire',
                    'published_at' => '2025-06-15T10:00:00+00:00',
                    'is_fabricated' => true,
                ],
                [
                    'headline' => "[MOCK] Liquidity note for {$sym} — advisory context only",
                    'source_label' => 'Nexa Mock Wire',
                    'published_at' => '2025-06-15T11:00:00+00:00',
                    'is_fabricated' => true,
                ],
            ],
        ];
    }
}
