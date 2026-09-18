<?php

namespace App\Services;

use App\Technical\MultiTimeframeTechnicalSnapshot;
use App\Technical\TechnicalSnapshot;

/**
 * Phase 7 Technical Analysis adapter.
 * Consumes MarketDataEngine closed candles + IndicatorEngineService.
 * Does NOT call MT5/bridge. Deterministic. No look-ahead (closed candles only).
 */
class TechnicalAnalysisEngine
{
    public function __construct(
        private readonly MarketDataEngineService $market,
        private readonly IndicatorEngineService $indicators,
    ) {}

    public function snapshot(
        string $symbol,
        string $timeframe = 'M5',
        int $count = 120,
        string $prefer = 'simulation',
    ): TechnicalSnapshot {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $candles = $this->market->getClosedCandles($symbol, $timeframe, $count, $prefer);
        $marketSnap = $this->market->snapshot([$symbol], $symbol, $timeframe, min(20, $count), $prefer, persist: false);
        $quality = $marketSnap['data_quality'] ?? ['status' => 'UNAVAILABLE'];
        $gate = $marketSnap['data_quality_gate'] ?? ['allowed' => false, 'reason' => 'NO_GATE'];

        $batch = $this->indicators->computeMany(
            ['SMA', 'EMA', 'RSI', 'MACD', 'ATR', 'BBANDS'],
            $symbol,
            $timeframe,
            $count,
            [
                'SMA' => ['period' => 20],
                'EMA' => ['period' => 12],
                'RSI' => ['period' => 14],
                'ATR' => ['period' => 14],
                'BBANDS' => ['period' => 20, 'stddev' => 2],
            ],
            $prefer,
        );

        $byName = [];
        foreach ($batch as $row) {
            $byName[strtoupper((string) ($row['indicator'] ?? ''))] = $row;
        }

        $ema50 = $this->indicators->compute('EMA', $symbol, $timeframe, $count, ['period' => 50], $prefer);
        $ema26 = $this->indicators->compute('EMA', $symbol, $timeframe, $count, ['period' => 26], $prefer);
        $byName['EMA50'] = $ema50;
        $byName['EMA26'] = $ema26;

        $lastClose = $this->lastFloat($candles, 'close');
        $atr = $this->valueOf($byName['ATR'] ?? [], 'atr') ?? $this->valueOf($byName['ATR'] ?? [], 'value');
        $structure = $this->deriveStructure($candles);
        $sr = $this->deriveSupportResistance($candles);
        $candleCloseKey = $this->candleCloseKey($candles, $symbol, $timeframe);

        $refused = collect($byName)->contains(fn ($row) => ($row['status'] ?? '') === 'REFUSED');
        $status = $refused || ! ($gate['allowed'] ?? false)
            ? 'REFUSED'
            : ((($quality['status'] ?? '') === 'DEGRADED') ? 'DEGRADED' : 'READY');

        return new TechnicalSnapshot(
            symbol: $symbol,
            timeframe: $timeframe,
            source: (string) ($marketSnap['source'] ?? 'UNKNOWN'),
            environment: (string) ($marketSnap['environment'] ?? 'SIMULATION'),
            status: $status,
            lastClose: $lastClose,
            sma20: $this->valueOf($byName['SMA'] ?? [], 'value'),
            ema12: $this->valueOf($byName['EMA'] ?? [], 'value'),
            ema26: $this->valueOf($ema26, 'value'),
            ema50: $this->valueOf($ema50, 'value'),
            rsi14: $this->valueOf($byName['RSI'] ?? [], 'rsi') ?? $this->valueOf($byName['RSI'] ?? [], 'value'),
            macd: $this->valueOf($byName['MACD'] ?? [], 'macd'),
            macdSignal: $this->valueOf($byName['MACD'] ?? [], 'signal'),
            macdHist: $this->valueOf($byName['MACD'] ?? [], 'histogram') ?? $this->valueOf($byName['MACD'] ?? [], 'hist'),
            atr14: $atr,
            bbUpper: $this->valueOf($byName['BBANDS'] ?? [], 'upper'),
            bbMiddle: $this->valueOf($byName['BBANDS'] ?? [], 'middle'),
            bbLower: $this->valueOf($byName['BBANDS'] ?? [], 'lower'),
            atr: $atr,
            candles: $candles,
            indicators: $byName,
            structure: $structure,
            supportResistance: $sr,
            quality: is_array($quality) ? $quality : [],
            gate: is_array($gate) ? $gate : [],
            candleCloseKey: $candleCloseKey,
            adapter: true,
        );
    }

    /**
     * @param  list<string>  $timeframes
     */
    public function multiTimeframe(
        string $symbol,
        string $primaryTimeframe = 'M5',
        array $timeframes = ['M15', 'H1'],
        int $count = 120,
        string $prefer = 'simulation',
    ): MultiTimeframeTechnicalSnapshot {
        $frames = [];
        $all = array_values(array_unique(array_merge([$primaryTimeframe], $timeframes)));
        foreach ($all as $tf) {
            $frames[strtoupper($tf)] = $this->snapshot($symbol, $tf, $count, $prefer);
        }

        $biases = [];
        foreach ($frames as $tf => $snap) {
            $biases[$tf] = $this->biasFromSnapshot($snap);
        }
        $aligned = $this->alignBias($biases[strtoupper($primaryTimeframe)] ?? 'NEUTRAL', $biases);

        $status = collect($frames)->contains(fn (TechnicalSnapshot $s) => $s->status === 'REFUSED')
            ? 'DEGRADED'
            : 'READY';

        return new MultiTimeframeTechnicalSnapshot(
            symbol: strtoupper($symbol),
            primaryTimeframe: strtoupper($primaryTimeframe),
            frames: $frames,
            alignedBias: $aligned,
            status: $status,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return array<string, mixed>
     */
    private function deriveStructure(array $candles): array
    {
        if (count($candles) < 10) {
            return ['bias' => 'UNKNOWN', 'swing_high' => null, 'swing_low' => null, 'hh' => false, 'hl' => false, 'lh' => false, 'll' => false];
        }
        $closes = array_map(fn ($c) => (float) $c['close'], $candles);
        $highs = array_map(fn ($c) => (float) $c['high'], $candles);
        $lows = array_map(fn ($c) => (float) $c['low'], $candles);
        $n = count($candles);
        $recentHigh = max(array_slice($highs, -5));
        $priorHigh = max(array_slice($highs, -10, 5));
        $recentLow = min(array_slice($lows, -5));
        $priorLow = min(array_slice($lows, -10, 5));
        $hh = $recentHigh > $priorHigh;
        $hl = $recentLow > $priorLow;
        $lh = $recentHigh < $priorHigh;
        $ll = $recentLow < $priorLow;
        $bias = 'RANGE';
        if ($hh && $hl) {
            $bias = 'BULLISH';
        } elseif ($lh && $ll) {
            $bias = 'BEARISH';
        }

        return [
            'bias' => $bias,
            'swing_high' => $recentHigh,
            'swing_low' => $recentLow,
            'hh' => $hh,
            'hl' => $hl,
            'lh' => $lh,
            'll' => $ll,
            'last_close' => $closes[$n - 1],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return array<string, mixed>
     */
    private function deriveSupportResistance(array $candles): array
    {
        if ($candles === []) {
            return ['support' => null, 'resistance' => null, 'mid' => null];
        }
        $highs = array_map(fn ($c) => (float) $c['high'], array_slice($candles, -40));
        $lows = array_map(fn ($c) => (float) $c['low'], array_slice($candles, -40));
        $resistance = max($highs);
        $support = min($lows);
        $mid = ($resistance + $support) / 2;

        return [
            'support' => $support,
            'resistance' => $resistance,
            'mid' => $mid,
            'distance_to_support' => abs(((float) end($candles)['close']) - $support),
            'distance_to_resistance' => abs($resistance - ((float) end($candles)['close'])),
        ];
    }

    private function biasFromSnapshot(TechnicalSnapshot $snap): string
    {
        if ($snap->ema12 !== null && $snap->ema26 !== null) {
            if ($snap->ema12 > $snap->ema26 && ($snap->lastClose ?? 0) > $snap->ema12) {
                return 'BULLISH';
            }
            if ($snap->ema12 < $snap->ema26 && ($snap->lastClose ?? 0) < $snap->ema12) {
                return 'BEARISH';
            }
        }

        return (string) ($snap->structure['bias'] ?? 'NEUTRAL');
    }

    /** @param  array<string, string>  $biases */
    private function alignBias(string $primary, array $biases): string
    {
        $others = array_values(array_filter($biases, fn ($b, $k) => true, ARRAY_FILTER_USE_BOTH));
        $bull = count(array_filter($biases, fn ($b) => $b === 'BULLISH'));
        $bear = count(array_filter($biases, fn ($b) => $b === 'BEARISH'));
        if ($primary === 'BULLISH' && $bull >= 2) {
            return 'BULLISH';
        }
        if ($primary === 'BEARISH' && $bear >= 2) {
            return 'BEARISH';
        }

        return 'MIXED';
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function candleCloseKey(array $candles, string $symbol, string $timeframe): string
    {
        if ($candles === []) {
            return strtoupper($symbol).'|'.strtoupper($timeframe).'|NONE';
        }
        $last = $candles[count($candles) - 1];
        $open = (string) ($last['open_time'] ?? $last['time'] ?? 'unknown');

        return strtoupper($symbol).'|'.strtoupper($timeframe).'|'.$open;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function lastFloat(array $candles, string $field): ?float
    {
        if ($candles === []) {
            return null;
        }
        $v = $candles[count($candles) - 1][$field] ?? null;

        return $v === null ? null : (float) $v;
    }

    /** @param  array<string, mixed>  $row */
    private function valueOf(array $row, string $key): ?float
    {
        $values = $row['values'] ?? [];
        if (! is_array($values)) {
            return null;
        }
        if (! array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }

        return (float) $values[$key];
    }
}
