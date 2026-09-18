<?php

namespace App\Contracts;

interface IndicatorProvider
{
    public function name(): string;

    public function label(): string;

    public function overlay(): bool;

    /**
     * @return array<string, mixed>
     */
    public function defaultParams(): array;

    /**
     * @return list<string>
     */
    public function outputs(): array;

    /**
     * @param  list<array<string, mixed>>  $closedCandles
     * @param  array<string, mixed>  $params
     * @return array{series: list<array<string, mixed>>, values: array<string, mixed>}
     */
    public function compute(array $closedCandles, array $params = []): array;
}
