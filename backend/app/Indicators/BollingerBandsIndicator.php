<?php

namespace App\Indicators;

class BollingerBandsIndicator extends AbstractIndicatorProvider
{
    public function name(): string
    {
        return 'BBANDS';
    }

    public function label(): string
    {
        return 'Bollinger Bands';
    }

    public function overlay(): bool
    {
        return true;
    }

    public function defaultParams(): array
    {
        return ['period' => 20, 'std_dev' => 2, 'source' => 'close'];
    }

    public function outputs(): array
    {
        return ['middle', 'upper', 'lower'];
    }

    public function compute(array $closedCandles, array $params = []): array
    {
        $period = max(1, (int) ($params['period'] ?? 20));
        $stdDev = (string) ($params['std_dev'] ?? 2);
        $source = (string) ($params['source'] ?? 'close');
        $values = $this->closes($closedCandles, $source);
        $mids = $this->smaSeries($values, $period);
        $series = [];
        $latest = [];

        foreach ($closedCandles as $index => $candle) {
            $mid = $mids[$index] ?? null;
            if ($mid === null) {
                continue;
            }
            $window = array_slice($values, $index - $period + 1, $period);
            $variance = '0';
            foreach ($window as $item) {
                $diff = $this->bc($item, '-', $mid);
                $variance = $this->bc($variance, '+', $this->bc($diff, '*', $diff));
            }
            $variance = $this->bc($variance, '/', (string) $period);
            $deviation = $this->bc($this->sqrt($variance), '*', $stdDev);
            $point = [
                'time' => $candle['open_time'] ?? null,
                'middle' => $this->quantize($mid),
                'upper' => $this->quantize($this->bc($mid, '+', $deviation)),
                'lower' => $this->quantize($this->bc($mid, '-', $deviation)),
            ];
            $series[] = $point;
            $latest = [
                'middle' => $point['middle'],
                'upper' => $point['upper'],
                'lower' => $point['lower'],
            ];
        }

        return ['series' => $series, 'values' => $latest];
    }

    private function sqrt(string $value): string
    {
        if (bccomp($value, '0', 12) <= 0) {
            return '0';
        }
        $x = $value;
        for ($i = 0; $i < 20; $i++) {
            $x = $this->bc(
                $this->bc($x, '+', $this->bc($value, '/', $x)),
                '/',
                '2'
            );
        }

        return $x;
    }
}
