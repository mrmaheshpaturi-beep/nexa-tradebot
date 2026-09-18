<?php

namespace App\Indicators;

class RsiIndicator extends AbstractIndicatorProvider
{
    public function name(): string
    {
        return 'RSI';
    }

    public function label(): string
    {
        return 'Relative Strength Index';
    }

    public function overlay(): bool
    {
        return false;
    }

    public function defaultParams(): array
    {
        return ['period' => 14, 'source' => 'close'];
    }

    public function outputs(): array
    {
        return ['value'];
    }

    public function compute(array $closedCandles, array $params = []): array
    {
        $period = max(1, (int) ($params['period'] ?? 14));
        $source = (string) ($params['source'] ?? 'close');
        $values = $this->closes($closedCandles, $source);
        $count = count($values);
        $computed = array_fill(0, $count, null);
        if ($count <= $period) {
            return ['series' => [], 'values' => []];
        }

        $gains = '0';
        $losses = '0';
        for ($i = 1; $i <= $period; $i++) {
            $delta = $this->bc($values[$i], '-', $values[$i - 1]);
            if (bccomp($delta, '0', 12) >= 0) {
                $gains = $this->bc($gains, '+', $delta);
            } else {
                $losses = $this->bc($losses, '+', $this->bc('0', '-', $delta));
            }
        }
        $avgGain = $this->bc($gains, '/', (string) $period);
        $avgLoss = $this->bc($losses, '/', (string) $period);
        $computed[$period] = $this->rsiFromAverages($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $count; $i++) {
            $delta = $this->bc($values[$i], '-', $values[$i - 1]);
            $gain = bccomp($delta, '0', 12) > 0 ? $delta : '0';
            $loss = bccomp($delta, '0', 12) < 0 ? $this->bc('0', '-', $delta) : '0';
            $avgGain = $this->bc(
                $this->bc($this->bc($avgGain, '*', (string) ($period - 1)), '+', $gain),
                '/',
                (string) $period
            );
            $avgLoss = $this->bc(
                $this->bc($this->bc($avgLoss, '*', (string) ($period - 1)), '+', $loss),
                '/',
                (string) $period
            );
            $computed[$i] = $this->rsiFromAverages($avgGain, $avgLoss);
        }

        return [
            'series' => $this->singleSeries($closedCandles, $computed),
            'values' => $this->latestSingle($computed),
        ];
    }

    private function rsiFromAverages(string $avgGain, string $avgLoss): string
    {
        if (bccomp($avgLoss, '0', 12) === 0) {
            return '100';
        }
        $rs = $this->bc($avgGain, '/', $avgLoss);

        return $this->bc('100', '-', $this->bc('100', '/', $this->bc('1', '+', $rs)));
    }
}
