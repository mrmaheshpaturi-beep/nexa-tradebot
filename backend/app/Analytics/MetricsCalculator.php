<?php

namespace App\Analytics;

/**
 * Deterministic analytics metrics. No undisclosed random seeds.
 * Win rate is N/A when fewer than min_completed completed outcomes exist.
 */
final class MetricsCalculator
{
    public const MIN_COMPLETED_FOR_WIN_RATE = 1;

    /**
     * @param  list<array<string, mixed>>  $rows  each with realized_pnl, r_multiple, mae, mfe, costs..., duration_seconds?, management flags
     * @return array<string, mixed>
     */
    public function compute(array $rows, int $minCompletedForWinRate = self::MIN_COMPLETED_FOR_WIN_RATE): array
    {
        $completed = array_values(array_filter($rows, fn ($r) => array_key_exists('realized_pnl', $r) && $r['realized_pnl'] !== null));
        $n = count($completed);
        $pnls = array_map(fn ($r) => (float) $r['realized_pnl'], $completed);
        $wins = array_values(array_filter($pnls, fn ($p) => $p > 0));
        $losses = array_values(array_filter($pnls, fn ($p) => $p < 0));
        $grossProfit = array_sum($wins);
        $grossLoss = abs(array_sum($losses));
        $net = array_sum($pnls);

        $winRate = $n >= $minCompletedForWinRate
            ? ($n > 0 ? count($wins) / $n : null)
            : null;

        $rValues = array_values(array_filter(array_map(
            fn ($r) => $r['r_multiple'] ?? null,
            $completed
        ), fn ($v) => $v !== null));
        $maeValues = array_values(array_filter(array_map(fn ($r) => $r['mae'] ?? null, $completed), fn ($v) => $v !== null));
        $mfeValues = array_values(array_filter(array_map(fn ($r) => $r['mfe'] ?? null, $completed), fn ($v) => $v !== null));

        $equity = $this->equityCurve($pnls);
        $maxDd = $this->maxDrawdown($equity);
        $returns = $pnls;
        $sharpe = $this->sharpe($returns);
        $sortino = $this->sortino($returns);
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? null : 0.0);

        $spread = $this->sumField($completed, 'spread_cost');
        $commission = $this->sumField($completed, 'commission');
        $slippage = $this->sumField($completed, 'slippage');
        $swap = $this->sumField($completed, 'swap');

        $beCount = count(array_filter($completed, fn ($r) => ! empty($r['break_even_applied'])));
        $trailCount = count(array_filter($completed, fn ($r) => ! empty($r['trailing_used'])));
        $partials = array_sum(array_map(fn ($r) => (int) ($r['partials_count'] ?? 0), $completed));

        return [
            'core' => [
                'completed_trades' => $n,
                'wins' => count($wins),
                'losses' => count($losses),
                'breakevens' => count(array_filter($pnls, fn ($p) => $p == 0.0)),
                'win_rate' => $winRate,
                'win_rate_status' => $winRate === null ? 'N/A_INSUFFICIENT_COMPLETED_OUTCOMES' : 'OK',
                'net_pnl' => $this->round4($net),
                'gross_profit' => $this->round4($grossProfit),
                'gross_loss' => $this->round4($grossLoss),
                'profit_factor' => $profitFactor === null ? null : $this->round8($profitFactor),
                'avg_pnl' => $n > 0 ? $this->round4($net / $n) : null,
                'expectancy' => $n > 0 ? $this->round4($net / $n) : null,
            ],
            'risk_adjusted' => [
                'sharpe' => $sharpe,
                'sortino' => $sortino,
                'max_drawdown' => $maxDd,
                'max_drawdown_pct' => $equity !== [] && $equity[0] != 0
                    ? $this->round8($maxDd / max(abs($equity[0]), 1e-12))
                    : null,
                'calmar' => ($maxDd > 0 && $n > 0) ? $this->round8(($net) / $maxDd) : null,
            ],
            'r_multiple' => [
                'count' => count($rValues),
                'avg_r' => count($rValues) ? $this->round8(array_sum($rValues) / count($rValues)) : null,
                'median_r' => count($rValues) ? $this->round8($this->median($rValues)) : null,
                'best_r' => count($rValues) ? $this->round8(max($rValues)) : null,
                'worst_r' => count($rValues) ? $this->round8(min($rValues)) : null,
            ],
            'mae_mfe' => [
                'avg_mae' => count($maeValues) ? $this->round8(array_sum($maeValues) / count($maeValues)) : null,
                'avg_mfe' => count($mfeValues) ? $this->round8(array_sum($mfeValues) / count($mfeValues)) : null,
                'avg_mae_to_mfe' => (count($maeValues) && count($mfeValues) && array_sum(array_map('abs', $mfeValues)) > 0)
                    ? $this->round8(array_sum(array_map('abs', $maeValues)) / array_sum(array_map('abs', $mfeValues)))
                    : null,
            ],
            'cost' => [
                'total_spread' => $this->round8($spread),
                'total_commission' => $this->round8($commission),
                'total_slippage' => $this->round8($slippage),
                'total_swap' => $this->round8($swap),
                'total_costs' => $this->round8($spread + $commission + $slippage + $swap),
                'net_after_costs_note' => 'realized_pnl assumed net of modeled costs when present in row',
            ],
            'execution' => [
                'fill_count' => $n,
                'avg_slippage' => $n > 0 ? $this->round8($slippage / $n) : null,
                'avg_spread_cost' => $n > 0 ? $this->round8($spread / $n) : null,
            ],
            'management' => [
                'break_even_applied_count' => $beCount,
                'trailing_used_count' => $trailCount,
                'partials_total' => $partials,
                'be_rate' => $n > 0 ? $this->round8($beCount / $n) : null,
                'trail_rate' => $n > 0 ? $this->round8($trailCount / $n) : null,
            ],
            'equity_curve' => $equity,
            'deterministic' => true,
            'random_seed_used' => null,
        ];
    }

    /** @param list<float> $pnls @return list<float> */
    public function equityCurve(array $pnls, float $start = 0.0): array
    {
        $eq = [];
        $cur = $start;
        foreach ($pnls as $p) {
            $cur += $p;
            $eq[] = $this->round4($cur);
        }

        return $eq;
    }

    /** @param list<float> $equity */
    public function maxDrawdown(array $equity): float
    {
        $peak = 0.0;
        $maxDd = 0.0;
        foreach ($equity as $v) {
            $peak = max($peak, $v);
            $maxDd = max($maxDd, $peak - $v);
        }

        return $this->round4($maxDd);
    }

    /** @param list<float> $returns */
    public function sharpe(array $returns, float $riskFree = 0.0): ?float
    {
        $n = count($returns);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($returns) / $n;
        $var = 0.0;
        foreach ($returns as $r) {
            $var += ($r - $mean) ** 2;
        }
        $std = sqrt($var / ($n - 1));
        if ($std < 1e-12) {
            return null;
        }

        return $this->round8(($mean - $riskFree) / $std);
    }

    /** @param list<float> $returns */
    public function sortino(array $returns, float $riskFree = 0.0): ?float
    {
        $n = count($returns);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($returns) / $n;
        $down = [];
        foreach ($returns as $r) {
            if ($r < $riskFree) {
                $down[] = ($r - $riskFree) ** 2;
            }
        }
        if ($down === []) {
            return null;
        }
        $dd = sqrt(array_sum($down) / count($down));
        if ($dd < 1e-12) {
            return null;
        }

        return $this->round8(($mean - $riskFree) / $dd);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumField(array $rows, string $field): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            if (isset($r[$field]) && $r[$field] !== null) {
                $sum += (float) $r[$field];
            }
        }

        return $sum;
    }

    private function round4(float $v): float
    {
        return round($v, 4);
    }

    private function round8(float $v): float
    {
        return round($v, 8);
    }
}
