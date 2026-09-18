<?php

namespace App\Indicators;

class MacdIndicator extends AbstractIndicatorProvider
{
    public function name(): string
    {
        return 'MACD';
    }

    public function label(): string
    {
        return 'Moving Average Convergence Divergence';
    }

    public function overlay(): bool
    {
        return false;
    }

    public function defaultParams(): array
    {
        return ['fast' => 12, 'slow' => 26, 'signal' => 9, 'source' => 'close'];
    }

    public function outputs(): array
    {
        return ['macd', 'signal', 'histogram'];
    }

    public function compute(array $closedCandles, array $params = []): array
    {
        $fast = max(1, (int) ($params['fast'] ?? 12));
        $slow = max($fast + 1, (int) ($params['slow'] ?? 26));
        $signalPeriod = max(1, (int) ($params['signal'] ?? 9));
        $source = (string) ($params['source'] ?? 'close');
        $values = $this->closes($closedCandles, $source);
        $fastEma = $this->emaSeries($values, $fast);
        $slowEma = $this->emaSeries($values, $slow);
        $macdLine = [];
        foreach ($values as $index => $_) {
            if ($fastEma[$index] === null || $slowEma[$index] === null) {
                $macdLine[$index] = null;
            } else {
                $macdLine[$index] = $this->bc($fastEma[$index], '-', $slowEma[$index]);
            }
        }
        $compact = array_values(array_filter($macdLine, fn ($v) => $v !== null));
        $signalCompact = $this->emaSeries($compact, $signalPeriod);
        $signalLine = array_fill(0, count($values), null);
        $compactIndex = 0;
        foreach ($macdLine as $index => $value) {
            if ($value === null) {
                continue;
            }
            $signalLine[$index] = $signalCompact[$compactIndex] ?? null;
            $compactIndex++;
        }

        $series = [];
        $latest = [];
        foreach ($closedCandles as $index => $candle) {
            $macd = $macdLine[$index] ?? null;
            $signal = $signalLine[$index] ?? null;
            if ($macd === null) {
                continue;
            }
            $histogram = $signal === null ? null : $this->bc($macd, '-', $signal);
            $point = [
                'time' => $candle['open_time'] ?? null,
                'macd' => $this->quantize($macd),
                'signal' => $this->quantize($signal),
                'histogram' => $this->quantize($histogram),
            ];
            $series[] = $point;
            $latest = [
                'macd' => $point['macd'],
                'signal' => $point['signal'],
                'histogram' => $point['histogram'],
            ];
        }

        return ['series' => $series, 'values' => $latest];
    }
}
