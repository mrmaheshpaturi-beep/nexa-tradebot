<?php

namespace App\Backtest;

/**
 * Phase 11 management logic reuse in BACKTEST simulation — NEVER calls MT5.
 * Supports break-even move and simple trailing; partial close optional.
 */
final class SimulatedManagementAdapter
{
    /**
     * @param  array<string, mixed>  $position
     * @param  array{open:float,high:float,low:float,close:float}  $bar
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed> updated position + events
     */
    public function manage(array $position, array $bar, array $policy, IntrabarPolicy $intrabar): array
    {
        $events = [];
        $dir = (string) $position['direction'];
        $buy = strtoupper($dir) === 'BUY';
        $entry = (float) $position['entry'];
        $sl = $position['sl'] !== null ? (float) $position['sl'] : null;
        $tp = $position['tp'] !== null ? (float) $position['tp'] : null;
        $initialRisk = abs($entry - (float) ($position['initial_sl'] ?? $sl ?? $entry));
        $mark = (float) $bar['close'];
        $favorable = $buy ? ($mark - $entry) : ($entry - $mark);
        $rMult = $initialRisk > 1e-12 ? $favorable / $initialRisk : 0.0;

        // MAE / MFE update from bar extremes
        $favExt = $buy ? ((float) $bar['high'] - $entry) : ($entry - (float) $bar['low']);
        $advExt = $buy ? ((float) $bar['low'] - $entry) : ($entry - (float) $bar['high']);
        $position['mfe'] = max((float) ($position['mfe'] ?? 0), $favExt);
        $position['mae'] = min((float) ($position['mae'] ?? 0), $advExt);
        $position['r_multiple'] = $rMult;

        // Break-even
        if (! empty($policy['break_even_enabled']) && empty($position['break_even_applied'])) {
            $trigger = (float) ($policy['break_even_trigger_r'] ?? 1.0);
            $offset = (float) ($policy['break_even_offset'] ?? 0.0);
            if ($rMult >= $trigger) {
                $newSl = $buy ? $entry + $offset : $entry - $offset;
                // never worsen
                if ($sl === null || ($buy && $newSl > $sl) || (! $buy && $newSl < $sl)) {
                    $sl = $newSl;
                    $position['sl'] = $sl;
                    $position['break_even_applied'] = true;
                    $events[] = ['type' => 'MOVE_BREAK_EVEN', 'sl' => $sl, 'r' => $rMult];
                }
            }
        }

        // Trailing
        if (! empty($policy['trailing_enabled'])) {
            $startR = (float) ($policy['trailing_start_r'] ?? 1.5);
            $distance = (float) ($policy['trailing_distance'] ?? 0.0);
            if ($distance <= 0) {
                $distance = abs($entry - (float) ($position['initial_sl'] ?? $entry)) * 0.5;
            }
            if ($rMult >= $startR) {
                $trailSl = $buy ? ((float) $bar['high'] - $distance) : ((float) $bar['low'] + $distance);
                if ($sl === null || ($buy && $trailSl > $sl) || (! $buy && $trailSl < $sl)) {
                    $sl = $trailSl;
                    $position['sl'] = $sl;
                    $position['trailing_used'] = true;
                    $events[] = ['type' => 'TRAIL', 'sl' => $sl, 'r' => $rMult];
                }
            }
        }

        // Partial at TP1 (50%) then move SL to BE if configured
        if (! empty($policy['partial_at_r']) && empty($position['partial_done'])) {
            $partialR = (float) $policy['partial_at_r'];
            if ($rMult >= $partialR) {
                $closeFrac = (float) ($policy['partial_fraction'] ?? 0.5);
                $closeFrac = max(0.1, min(0.9, $closeFrac));
                $position['partial_done'] = true;
                $position['partials_count'] = ((int) ($position['partials_count'] ?? 0)) + 1;
                $position['volume'] = round((float) $position['volume'] * (1 - $closeFrac), 2);
                $events[] = ['type' => 'PARTIAL_CLOSE', 'fraction' => $closeFrac, 'r' => $rMult];
            }
        }

        $hit = $intrabar->resolveExit($dir, $bar, $sl, $tp);
        if ($hit !== null) {
            $position['exit_hit'] = $hit['hit'];
            $position['exit_price'] = $hit['price'];
            $position['closed'] = true;
            $events[] = ['type' => 'EXIT_'.$hit['hit'], 'price' => $hit['price']];
        }

        $position['events'] = array_merge($position['events'] ?? [], $events);

        return $position;
    }
}
