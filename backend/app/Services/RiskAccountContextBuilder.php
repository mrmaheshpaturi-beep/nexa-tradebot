<?php

namespace App\Services;

use App\Enums\PositionStatus;
use App\Enums\RiskReservationStatus;
use App\Models\BrokerAccount;
use App\Models\Deal;
use App\Models\Position;
use App\Models\RiskReservation;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use Carbon\Carbon;

class RiskAccountContextBuilder
{
    /**
     * Deterministic account/portfolio context for risk evaluation.
     *
     * @return array{
     *   balance:float,equity:float,margin:float,free_margin:float,margin_level:float|null,
     *   floating_pnl:float,drawdown:float,open_positions:int,reserved_margin:float,
     *   reserved_risk:float,reserved_exposure:float,daily_realized_pnl:float,
     *   weekly_realized_pnl:float,consecutive_losses:int,trades_today:int,
     *   open_risk_percent:float,correlated_exposure_percent:float,leverage:int
     * }
     */
    public function build(BrokerAccount $account, TradingInstrument $instrument, ?Carbon $at = null): array
    {
        $at ??= now();
        $snapshot = $account->snapshots()->latest('captured_at')->latest('id')->first();
        $balance = (float) ($snapshot?->balance ?? 0);
        $equity = (float) ($snapshot?->equity ?? $balance);
        $margin = (float) ($snapshot?->margin ?? 0);
        $freeMargin = (float) ($snapshot?->free_margin ?? max(0, $equity - $margin));
        $marginLevel = $snapshot?->margin_level !== null ? (float) $snapshot->margin_level : ($margin > 0 ? ($equity / $margin) * 100 : null);
        $floating = (float) ($snapshot?->floating_pnl ?? 0);
        $drawdown = (float) ($snapshot?->drawdown ?? 0);

        $openPositions = $account->positions()
            ->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
            ->with('instrument')
            ->get();

        $reservations = RiskReservation::query()
            ->where('broker_account_id', $account->id)
            ->where('status', RiskReservationStatus::Active->value)
            ->where(function ($q) use ($at): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            })
            ->get();

        $reservedMargin = (float) $reservations->sum('reserved_margin');
        $reservedRisk = (float) $reservations->sum('reserved_risk');
        $reservedExposure = (float) $reservations->sum('reserved_exposure');

        $dayStart = $at->copy()->utc()->startOfDay();
        $weekStart = $at->copy()->utc()->startOfWeek(Carbon::MONDAY);
        $dailyPnl = (float) Deal::query()
            ->where('broker_account_id', $account->id)
            ->where('executed_at', '>=', $dayStart)
            ->sum('profit');
        $weeklyPnl = (float) Deal::query()
            ->where('broker_account_id', $account->id)
            ->where('executed_at', '>=', $weekStart)
            ->sum('profit');

        $closed = $account->positions()
            ->where('status', PositionStatus::Closed->value)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['realized_pnl']);
        $streak = 0;
        foreach ($closed as $position) {
            if ((float) $position->realized_pnl < 0) {
                $streak++;
            } else {
                break;
            }
        }

        $tradesToday = TradeIntent::query()
            ->where('broker_account_id', $account->id)
            ->where('created_at', '>=', $dayStart)
            ->count();

        $openRisk = 0.0;
        $correlated = 0.0;
        $group = $this->correlationGroup($instrument->symbol);
        foreach ($openPositions as $position) {
            /** @var Position $position */
            $posInstrument = $position->instrument;
            if (! $posInstrument || $position->stop_loss === null) {
                continue;
            }
            $riskAmt = abs((float) $position->average_entry_price - (float) $position->stop_loss)
                * (float) $position->current_volume
                * (float) $posInstrument->contract_size;
            $pct = $equity > 0 ? ($riskAmt / $equity) * 100 : 0;
            $openRisk += $pct;
            if ($this->correlationGroup($posInstrument->symbol) === $group) {
                $correlated += $pct;
            }
        }

        return [
            'balance' => $balance,
            'equity' => $equity,
            'margin' => $margin,
            'free_margin' => $freeMargin,
            'margin_level' => $marginLevel,
            'floating_pnl' => $floating,
            'drawdown' => $drawdown,
            'open_positions' => $openPositions->count(),
            'reserved_margin' => $reservedMargin,
            'reserved_risk' => $reservedRisk,
            'reserved_exposure' => $reservedExposure,
            'daily_realized_pnl' => $dailyPnl,
            'weekly_realized_pnl' => $weeklyPnl,
            'consecutive_losses' => $streak,
            'trades_today' => $tradesToday,
            'open_risk_percent' => round($openRisk, 4),
            'correlated_exposure_percent' => round($correlated, 4),
            'leverage' => (int) ($account->leverage ?: 1),
        ];
    }

    public function correlationGroup(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        if (str_contains($symbol, 'USD') && (str_starts_with($symbol, 'EUR') || str_ends_with($symbol, 'EUR'))) {
            return 'EUR_USD_COMPLEX';
        }
        if (str_contains($symbol, 'USD') && (str_starts_with($symbol, 'GBP') || str_ends_with($symbol, 'GBP'))) {
            return 'GBP_USD_COMPLEX';
        }
        if (str_contains($symbol, 'JPY')) {
            return 'JPY_COMPLEX';
        }
        if (str_contains($symbol, 'XAU') || str_contains($symbol, 'GOLD')) {
            return 'GOLD_COMPLEX';
        }

        return 'SYMBOL:'.$symbol;
    }
}
