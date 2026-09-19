<?php

namespace App\Intelligence\Providers;

interface NewsProviderInterface
{
    public function name(): string;

    /**
     * @return array{provider_status: string, items: list<array<string, mixed>>, is_fabricated: bool, disclaimer: string}
     */
    public function fetch(?string $symbol = null): array;
}
