<?php

namespace App\Indicators;

use App\Contracts\IndicatorProvider;
use InvalidArgumentException;

abstract class AbstractIndicatorProvider implements IndicatorProvider
{
    protected function closes(array $candles, string $source = 'close'): array
    {
        $values = [];
        foreach ($candles as $candle) {
            if (! isset($candle[$source])) {
                throw new InvalidArgumentException("Missing candle source field: {$source}");
            }
            $values[] = (string) $candle[$source];
        }

        return $values;
    }

    protected function bc(string $left, string $op, string $right, int $scale = 12): string
    {
        return match ($op) {
            '+' => bcadd($left, $right, $scale),
            '-' => bcsub($left, $right, $scale),
            '*' => bcmul($left, $right, $scale),
            '/' => bcdiv($left, $right, $scale),
            default => throw new InvalidArgumentException("Unsupported op {$op}"),
        };
    }

    protected function quantize(?string $value, int $places = 8): ?string
    {
        if ($value === null) {
            return null;
        }

        return bcadd($value, '0', $places);
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  list<?string>  $computed
     * @return list<array<string, mixed>>
     */
    protected function singleSeries(array $candles, array $computed): array
    {
        $series = [];
        foreach ($candles as $index => $candle) {
            $value = $computed[$index] ?? null;
            if ($value === null) {
                continue;
            }
            $series[] = [
                'time' => $candle['open_time'] ?? null,
                'value' => $this->quantize($value),
            ];
        }

        return $series;
    }

    /**
     * @param  list<?string>  $computed
     * @return array<string, mixed>
     */
    protected function latestSingle(array $computed): array
    {
        for ($index = count($computed) - 1; $index >= 0; $index--) {
            if ($computed[$index] !== null) {
                return ['value' => $this->quantize($computed[$index])];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $values
     * @return list<?string>
     */
    protected function smaSeries(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);
        if ($period < 1 || $count < $period) {
            return $result;
        }
        $window = '0';
        for ($i = 0; $i < $period; $i++) {
            $window = $this->bc($window, '+', $values[$i]);
        }
        $result[$period - 1] = $this->bc($window, '/', (string) $period);
        for ($i = $period; $i < $count; $i++) {
            $window = $this->bc($this->bc($window, '+', $values[$i]), '-', $values[$i - $period]);
            $result[$i] = $this->bc($window, '/', (string) $period);
        }

        return $result;
    }

    /**
     * @param  list<string>  $values
     * @return list<?string>
     */
    protected function emaSeries(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);
        if ($period < 1 || $count < $period) {
            return $result;
        }
        $seed = '0';
        for ($i = 0; $i < $period; $i++) {
            $seed = $this->bc($seed, '+', $values[$i]);
        }
        $prev = $this->bc($seed, '/', (string) $period);
        $result[$period - 1] = $prev;
        $multiplier = $this->bc('2', '/', (string) ($period + 1));
        for ($i = $period; $i < $count; $i++) {
            $prev = $this->bc(
                $this->bc($this->bc($values[$i], '-', $prev), '*', $multiplier),
                '+',
                $prev
            );
            $result[$i] = $prev;
        }

        return $result;
    }
}
