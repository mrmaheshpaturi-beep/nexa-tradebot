<?php

namespace App\Backtest;

/**
 * Safe parameter optimization overfitting controls.
 */
final class OverfittingControls
{
    public const MAX_TRIALS = 40;
    public const MIN_OOS_TRADES = 5;
    public const MAX_IS_OOS_DEGRADATION = 0.5; // OOS expectancy must be >= 50% of IS

    /**
     * @param  array<string, mixed>  $isMetrics
     * @param  array<string, mixed>  $oosMetrics
     * @return array{overfit_flag:bool,notes:list<string>}
     */
    public function evaluate(array $isMetrics, array $oosMetrics, int $trialCount): array
    {
        $notes = [];
        $flag = false;
        if ($trialCount > self::MAX_TRIALS) {
            $flag = true;
            $notes[] = 'TRIAL_COUNT_EXCEEDED_SOFT_CAP';
        }
        $oosTrades = (int) ($oosMetrics['core']['completed_trades'] ?? 0);
        if ($oosTrades < self::MIN_OOS_TRADES) {
            $flag = true;
            $notes[] = 'INSUFFICIENT_OOS_TRADES';
        }
        $isExp = $isMetrics['core']['expectancy'] ?? null;
        $oosExp = $oosMetrics['core']['expectancy'] ?? null;
        if ($isExp !== null && $oosExp !== null && $isExp > 0) {
            if ($oosExp < $isExp * self::MAX_IS_OOS_DEGRADATION) {
                $flag = true;
                $notes[] = 'OOS_EXPECTANCY_DEGRADED';
            }
        }
        $isWr = $isMetrics['core']['win_rate'] ?? null;
        $oosWr = $oosMetrics['core']['win_rate'] ?? null;
        if ($isWr !== null && $oosWr !== null && $isWr - $oosWr > 0.25) {
            $flag = true;
            $notes[] = 'WIN_RATE_IS_OOS_GAP';
        }

        return ['overfit_flag' => $flag, 'notes' => $notes];
    }

    /**
     * Generate bounded grid from parameter ranges (deterministic order).
     *
     * @param  array<string, array{min:float|int,max:float|int,step:float|int}>  $ranges
     * @return list<array<string, float|int>>
     */
    public function grid(array $ranges, int $maxTrials = self::MAX_TRIALS): array
    {
        $keys = array_keys($ranges);
        if ($keys === []) {
            return [[]];
        }
        $axes = [];
        foreach ($keys as $k) {
            $min = $ranges[$k]['min'];
            $max = $ranges[$k]['max'];
            $step = $ranges[$k]['step'] ?: 1;
            $vals = [];
            for ($v = $min; $v <= $max + 1e-12; $v += $step) {
                $vals[] = is_int($step) && is_int($min) ? (int) round($v) : round((float) $v, 8);
            }
            $axes[$k] = $vals;
        }
        $combos = [[]];
        foreach ($axes as $k => $vals) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($vals as $v) {
                    $next[] = $c + [$k => $v];
                }
            }
            $combos = $next;
            if (count($combos) > $maxTrials) {
                break;
            }
        }

        return array_slice($combos, 0, $maxTrials);
    }
}
