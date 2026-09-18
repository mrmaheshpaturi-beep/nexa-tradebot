<?php

namespace App\Services;

use Carbon\Carbon;

class CandleGapDetector
{
    /**
     * @param  list<array<string, mixed>>  $bars
     * @return list<array{from:string,to:string,classification:string,missing_bars_estimate:int}>
     */
    public function detect(array $bars, string $timeframe): array
    {
        if (count($bars) < 2) {
            return [];
        }
        $sorted = collect($bars)->sortBy('open_time')->values()->all();
        $stepMinutes = $this->stepMinutes($timeframe);
        $gaps = [];
        for ($i = 1; $i < count($sorted); $i++) {
            $prev = Carbon::parse($sorted[$i - 1]['open_time'])->utc();
            $curr = Carbon::parse($sorted[$i]['open_time'])->utc();
            $diff = $prev->diffInMinutes($curr);
            if ($diff <= $stepMinutes) {
                continue;
            }
            $missing = (int) max(0, ($diff / $stepMinutes) - 1);
            $classification = $this->classify($prev, $curr, $missing);
            $gaps[] = [
                'from' => $prev->toIso8601String(),
                'to' => $curr->toIso8601String(),
                'classification' => $classification,
                'missing_bars_estimate' => $missing,
            ];
        }

        return $gaps;
    }

    public function stepMinutes(string $timeframe): int
    {
        return match (strtoupper($timeframe)) {
            'M1' => 1,
            'M5' => 5,
            'M15' => 15,
            'M30' => 30,
            'H1' => 60,
            'H4' => 240,
            'D1' => 1440,
            default => 5,
        };
    }

    private function classify(Carbon $from, Carbon $to, int $missing): string
    {
        // Weekend-sized gaps on forex-scale timeframes are expected closures.
        if ($from->diffInHours($to) >= 40) {
            return 'EXPECTED_MARKET_CLOSURE';
        }
        if ($missing >= 1 && $missing <= 3) {
            return 'POSSIBLE_DATA_GAP';
        }

        return 'UNKNOWN';
    }

    /**
     * @return list<array{symbol:string,timeframe:string,open_time:string,is_closed:bool}>
     */
    public function markClosed(array $bars, string $timeframe): array
    {
        $step = $this->stepMinutes($timeframe);
        $now = now()->utc();
        $out = [];
        foreach ($bars as $bar) {
            $open = Carbon::parse($bar['open_time'])->utc();
            $expectedClose = $open->copy()->addMinutes($step);
            $isClosed = $expectedClose->lte($now);
            $bar['is_closed'] = $isClosed;
            $bar['close_time'] = $bar['close_time'] ?? $expectedClose->toIso8601String();
            $out[] = $bar;
        }

        return $out;
    }
}
