<?php

namespace App\Services;

use App\Models\BrokerAccount;

class AccountStateCalculator
{
    /** @return array{balance:float,equity:float,margin:float,free_margin:float,margin_level:?float,floating_pnl:float,drawdown:float,open_positions:int} */
    public function calculate(BrokerAccount $account, float $realizedAdjustment = 0): array
    {
        $latest = $account->snapshots()->latest('captured_at')->first();
        $balance = (float) ($latest?->balance ?? 0) + $realizedAdjustment;
        $openPositions = $account->positions()->whereIn('status', ['OPEN', 'PARTIALLY_CLOSED']);
        $floating = (float) (clone $openPositions)->sum('unrealized_pnl');
        $margin = (float) (clone $openPositions)->sum('margin_used');
        $equity = round($balance + $floating, 4);
        $freeMargin = round($equity - $margin, 4);

        return [
            'balance' => $balance,
            'equity' => $equity,
            'margin' => $margin,
            'free_margin' => $freeMargin,
            'margin_level' => $margin > 0.00000001 ? round(($equity / $margin) * 100, 4) : null,
            'floating_pnl' => round($floating, 4),
            'drawdown' => $balance > 0.00000001 ? round(max(0, ($balance - $equity) / $balance * 100), 4) : 0.0,
            'open_positions' => (clone $openPositions)->count(),
        ];
    }
}
