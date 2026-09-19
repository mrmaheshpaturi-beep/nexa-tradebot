<?php

namespace App\TradeManagement;

use App\Models\ManagedPosition;
use App\Models\TradeManagementEvent;
use App\Models\TradeSummary;

class TradeSummaryService
{
    public function finalize(ManagedPosition $position): TradeSummary
    {
        $existing = TradeSummary::query()->where('managed_position_id', $position->id)->first();
        if ($existing && $existing->finalized) {
            return $existing;
        }

        $timeline = TradeManagementEvent::query()
            ->where('managed_position_id', $position->id)
            ->orderBy('occurred_at')
            ->get(['event_type', 'payload', 'occurred_at'])
            ->toArray();

        $partials = $position->positionTargets()->whereNotNull('hit_at')->count();

        $data = [
            'user_id' => $position->user_id,
            'symbol' => $position->symbol,
            'direction' => $position->direction,
            'entry_price' => $position->entry_price,
            'exit_price' => $position->metadata['exit_price'] ?? $position->current_take_profit,
            'initial_volume' => $position->initial_volume,
            'closed_volume' => $position->initial_volume,
            'realized_pnl' => $position->realized_profit,
            'mae' => $position->mae,
            'mfe' => $position->mfe,
            'r_multiple' => $position->r_multiple,
            'close_reason' => $position->close_reason,
            'break_even_applied' => $position->break_even_applied,
            'trailing_used' => $position->trailing_active,
            'partials_count' => $partials,
            'timeline' => $timeline,
            'finalized' => true,
            'opened_at' => $position->opened_at,
            'closed_at' => $position->closed_at ?? now(),
            'finalized_at' => now(),
        ];

        if ($existing) {
            $existing->forceFill($data)->save();

            return $existing->fresh();
        }

        return TradeSummary::query()->create(array_merge($data, [
            'managed_position_id' => $position->id,
        ]));
    }

    public function updateMaeMfe(ManagedPosition $position, float $mark): void
    {
        $entry = (float) $position->entry_price;
        $dirBuy = $position->direction->value === 'BUY';
        $favorable = $dirBuy ? ($mark - $entry) : ($entry - $mark);
        $adverse = -$favorable;
        $mfe = $position->mfe !== null ? max((float) $position->mfe, $favorable) : max(0, $favorable);
        $mae = $position->mae !== null ? min((float) $position->mae, $adverse) : min(0, $adverse);
        $position->forceFill(['mfe' => $mfe, 'mae' => $mae])->save();
    }
}
