<?php

namespace App\Indicators;

class AtrIndicator extends AbstractIndicatorProvider
{
    public function name(): string
    {
        return 'ATR';
    }

    public function label(): string
    {
        return 'Average True Range';
    }

    public function overlay(): bool
    {
        return false;
    }

    public function defaultParams(): array
    {
        return ['period' => 14];
    }

    public function outputs(): array
    {
        return ['value'];
    }

    public function compute(array $closedCandles, array $params = []): array
    {
        $period = max(1, (int) ($params['period'] ?? 14));
        $count = count($closedCandles);
        $computed = array_fill(0, $count, null);
        if ($count <= $period) {
            return ['series' => [], 'values' => []];
        }

        $trueRanges = [];
        foreach ($closedCandles as $index => $candle) {
            $high = (string) $candle['high'];
            $low = (string) $candle['low'];
            $close = (string) $candle['close'];
            if ($index === 0) {
                $trueRanges[] = $this->bc($high, '-', $low);
                continue;
            }
            $prevClose = (string) $closedCandles[$index - 1]['close'];
            $range = $this->bc($high, '-', $low);
            $highPrev = ltrim($this->bc($high, '-', $prevClose), '-');
            $lowPrev = ltrim($this->bc($low, '-', $prevClose), '-');
            $trueRanges[] = $this->max3($range, $highPrev, $lowPrev);
        }

        $seed = '0';
        for ($i = 1; $i <= $period; $i++) {
            $seed = $this->bc($seed, '+', $trueRanges[$i]);
        }
        $prev = $this->bc($seed, '/', (string) $period);
        $computed[$period] = $prev;
        for ($i = $period + 1; $i < $count; $i++) {
            $prev = $this->bc(
                $this->bc($this->bc($prev, '*', (string) ($period - 1)), '+', $trueRanges[$i]),
                '/',
                (string) $period
            );
            $computed[$i] = $prev;
        }

        return [
            'series' => $this->singleSeries($closedCandles, $computed),
            'values' => $this->latestSingle($computed),
        ];
    }

    private function max3(string $a, string $b, string $c): string
    {
        $max = bccomp($a, $b, 12) >= 0 ? $a : $b;

        return bccomp($max, $c, 12) >= 0 ? $max : $c;
    }
}
