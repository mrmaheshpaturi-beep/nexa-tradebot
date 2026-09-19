<?php

namespace App\Backtest;

/**
 * Explicit intrabar OHLC path policy for stop/TP simulation inside a bar.
 *
 * Assumptions (documented):
 * - Signals are evaluated only on CLOSED candles (no lookahead).
 * - When a position is open, the next CLOSED candle's OHLC path is walked
 *   according to policy to decide which level is hit first.
 * - Default OHLC_PATH: for BUY — Open → Low → High → Close;
 *   for SELL — Open → High → Low → Close.
 *   (Conservative: adverse excursion checked before favorable for BUY via Low-before-High.)
 * - Alternate OLHC_PATH: Open → Low → High → Close for both (legacy alias).
 * - If both SL and TP could be hit in the path, the first touch in the path wins.
 * - Gap through levels on open: open price can trigger SL/TP immediately.
 */
final class IntrabarPolicy
{
    public const OHLC_PATH = 'OHLC_PATH';
    public const OLHC_PATH = 'OLHC_PATH';

    public function __construct(private readonly string $policy = self::OHLC_PATH) {}

    public function name(): string
    {
        return $this->policy;
    }

    /**
     * @param  array{open:float,high:float,low:float,close:float}  $bar
     * @return list<float> price path
     */
    public function path(string $direction, array $bar): array
    {
        $o = (float) $bar['open'];
        $h = (float) $bar['high'];
        $l = (float) $bar['low'];
        $c = (float) $bar['close'];
        $buy = strtoupper($direction) === 'BUY';

        return match ($this->policy) {
            self::OLHC_PATH => [$o, $l, $h, $c],
            default => $buy ? [$o, $l, $h, $c] : [$o, $h, $l, $c],
        };
    }

    /**
     * @param  array{open:float,high:float,low:float,close:float}  $bar
     * @return array{hit:string,price:float}|null hit = SL|TP|NONE
     */
    public function resolveExit(string $direction, array $bar, ?float $sl, ?float $tp): ?array
    {
        foreach ($this->path($direction, $bar) as $px) {
            $buy = strtoupper($direction) === 'BUY';
            if ($sl !== null) {
                if ($buy && $px <= $sl) {
                    return ['hit' => 'SL', 'price' => $sl];
                }
                if (! $buy && $px >= $sl) {
                    return ['hit' => 'SL', 'price' => $sl];
                }
            }
            if ($tp !== null) {
                if ($buy && $px >= $tp) {
                    return ['hit' => 'TP', 'price' => $tp];
                }
                if (! $buy && $px <= $tp) {
                    return ['hit' => 'TP', 'price' => $tp];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function documentation(): array
    {
        return [
            'policy' => $this->policy,
            'assumptions' => [
                'closed_candle_signals_only' => true,
                'no_lookahead' => true,
                'path_default_buy' => 'O-L-H-C',
                'path_default_sell' => 'O-H-L-C',
                'first_touch_wins' => true,
                'gap_open_can_trigger' => true,
            ],
        ];
    }
}
