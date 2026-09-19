<?php

namespace App\Backtest;

use App\Indicators\EmaIndicator;
use App\Indicators\RsiIndicator;
use App\Indicators\AtrIndicator;
use App\Indicators\SmaIndicator;

/**
 * Closed-candle technical builder for backtests (no MarketDataEngine / no MT5).
 * Only uses candles with index <= asOfIndex (inclusive closed bar).
 */
final class ClosedCandleTechnicalBuilder
{
    private EmaIndicator $ema;
    private RsiIndicator $rsi;
    private AtrIndicator $atr;
    private SmaIndicator $sma;

    public function __construct()
    {
        $this->ema = new EmaIndicator;
        $this->rsi = new RsiIndicator;
        $this->atr = new AtrIndicator;
        $this->sma = new SmaIndicator;
    }

    /**
     * @param  list<array<string, mixed>>  $allCandles
     * @return array<string, mixed>
     */
    public function at(array $allCandles, int $asOfIndex): array
    {
        if ($asOfIndex < 0 || $asOfIndex >= count($allCandles)) {
            return ['status' => 'REFUSED', 'reason' => 'INDEX_OOB'];
        }
        // Closed candles only through asOfIndex — never peek ahead
        $closed = array_slice($allCandles, 0, $asOfIndex + 1);
        $ema12 = $this->ema->compute($closed, ['period' => 12]);
        $ema26 = $this->ema->compute($closed, ['period' => 26]);
        $ema50 = $this->ema->compute($closed, ['period' => 50]);
        $rsi = $this->rsi->compute($closed, ['period' => 14]);
        $atr = $this->atr->compute($closed, ['period' => 14]);
        $sma20 = $this->sma->compute($closed, ['period' => 20]);
        $last = $closed[$asOfIndex];

        return [
            'status' => 'READY',
            'last_close' => (float) $last['close'],
            'ema12' => $this->latest($ema12),
            'ema26' => $this->latest($ema26),
            'ema50' => $this->latest($ema50),
            'rsi14' => $this->latest($rsi, 'rsi'),
            'atr14' => $this->latest($atr, 'atr'),
            'sma20' => $this->latest($sma20),
            'candle_count' => count($closed),
            'as_of_index' => $asOfIndex,
            'no_lookahead' => true,
        ];
    }

    /**
     * Higher-TF protection: only include HTF bars whose close_time <= LTF bar close_time.
     *
     * @param  list<array<string, mixed>>  $htfCandles
     * @param  array<string, mixed>  $ltfBar
     * @return list<array<string, mixed>>
     */
    public function closedHtfOnly(array $htfCandles, array $ltfBar): array
    {
        $ltfClose = $ltfBar['close_time'] ?? $ltfBar['open_time'] ?? null;
        if ($ltfClose === null) {
            return [];
        }
        $ltfTs = is_numeric($ltfClose) ? (int) $ltfClose : strtotime((string) $ltfClose);
        $out = [];
        foreach ($htfCandles as $bar) {
            $ct = $bar['close_time'] ?? $bar['open_time'] ?? null;
            if ($ct === null) {
                continue;
            }
            $ts = is_numeric($ct) ? (int) $ct : strtotime((string) $ct);
            if ($ts !== false && $ts <= $ltfTs) {
                $out[] = $bar;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $computed */
    private function latest(array $computed, string $key = 'value'): ?float
    {
        $values = $computed['values'] ?? [];
        if (isset($values[$key]) && $values[$key] !== null) {
            return (float) $values[$key];
        }
        if (isset($values['value']) && $values['value'] !== null) {
            return (float) $values['value'];
        }
        $series = $computed['series'] ?? [];
        if ($series === []) {
            return null;
        }
        $last = end($series);
        if (is_array($last)) {
            foreach ([$key, 'value', 'atr', 'rsi'] as $k) {
                if (isset($last[$k]) && $last[$k] !== null) {
                    return (float) $last[$k];
                }
            }
        }

        return is_numeric($last) ? (float) $last : null;
    }
}
