<?php

namespace App\Intelligence\Providers;

class UnavailableNewsProvider implements NewsProviderInterface
{
    public function name(): string
    {
        return 'UNAVAILABLE';
    }

    public function fetch(?string $symbol = null): array
    {
        return [
            'provider_status' => 'UNAVAILABLE',
            'is_fabricated' => false,
            'disclaimer' => 'News provider UNAVAILABLE — no headlines fabricated as real.',
            'items' => [],
        ];
    }
}
