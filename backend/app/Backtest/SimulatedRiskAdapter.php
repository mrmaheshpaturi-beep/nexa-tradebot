<?php

namespace App\Backtest;

/**
 * Phase 9 risk logic reuse in BACKTEST simulation only — NEVER calls MT5/bridge.
 * Applies max risk %, max lot, stop distance sizing similar to PositionSizingService.
 */
final class SimulatedRiskAdapter
{
    /**
     * @param  array<string, mixed>  $profile  risk profile-like config
     * @return array{approved:bool,volume:float,entry:float,sl:float,tp:?float,reason:?string,risk_amount:float}
     */
    public function size(
        string $direction,
        float $entryMid,
        float $atr,
        float $equity,
        array $profile = [],
        float $contractSize = 100000.0,
    ): array {
        $maxRiskPct = (float) ($profile['max_risk_per_trade'] ?? 1.0);
        $maxLot = (float) ($profile['max_lot_size'] ?? 1.0);
        $minLot = (float) ($profile['min_lot_size'] ?? 0.01);
        $slAtrMult = (float) ($profile['sl_atr_mult'] ?? 1.5);
        $tpAtrMult = (float) ($profile['tp_atr_mult'] ?? 2.5);
        $atr = max($atr, 1e-8);
        $buy = strtoupper($direction) === 'BUY';
        $slDist = $atr * $slAtrMult;
        $tpDist = $atr * $tpAtrMult;
        $sl = $buy ? $entryMid - $slDist : $entryMid + $slDist;
        $tp = $buy ? $entryMid + $tpDist : $entryMid - $tpDist;
        $riskAmount = $equity * ($maxRiskPct / 100.0);
        $volume = $riskAmount / ($slDist * $contractSize);
        $volume = max($minLot, min($maxLot, floor($volume / $minLot) * $minLot));
        if ($volume < $minLot || $equity <= 0) {
            return [
                'approved' => false,
                'volume' => 0.0,
                'entry' => $entryMid,
                'sl' => $sl,
                'tp' => $tp,
                'reason' => 'RISK_REJECT_SIZE',
                'risk_amount' => 0.0,
            ];
        }

        return [
            'approved' => true,
            'volume' => round($volume, 2),
            'entry' => $entryMid,
            'sl' => $sl,
            'tp' => $tp,
            'reason' => null,
            'risk_amount' => $riskAmount,
        ];
    }
}
