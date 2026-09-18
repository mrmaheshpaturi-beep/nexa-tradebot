<?php

namespace App\Indicators;

class SmaIndicator extends AbstractIndicatorProvider
{
    public function name(): string
    {
        return 'SMA';
    }

    public function label(): string
    {
        return 'Simple Moving Average';
    }

    public function overlay(): bool
    {
        return true;
    }

    public function defaultParams(): array
    {
        return ['period' => 20, 'source' => 'close'];
    }

    public function outputs(): array
    {
        return ['value'];
    }

    public function compute(array $closedCandles, array $params = []): array
    {
        $period = max(1, (int) ($params['period'] ?? 20));
        $source = (string) ($params['source'] ?? 'close');
        $values = $this->closes($closedCandles, $source);
        $computed = $this->smaSeries($values, $period);

        return [
            'series' => $this->singleSeries($closedCandles, $computed),
            'values' => $this->latestSingle($computed),
        ];
    }
}
