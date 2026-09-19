<?php

namespace App\TradeManagement;

use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Models\ManagedPosition;
use App\Models\Position;
use App\Models\TradeIntent;

/**
 * NEVER auto-manage foreign/manual/other-EA positions (Phase 11 §4).
 */
class PositionOwnershipService
{
    public function classifyFromLocalPosition(Position $position): PositionOwnership
    {
        $meta = $position->metadata ?? [];
        if (($meta['origin'] ?? null) === 'NEXA' || ($meta['nexa_managed'] ?? false) === true) {
            return PositionOwnership::NexaManaged;
        }
        if ($position->openingOrder?->tradeIntent !== null) {
            return PositionOwnership::NexaManaged;
        }
        if (($meta['foreign'] ?? false) === true) {
            return PositionOwnership::Foreign;
        }
        if (($meta['manual'] ?? false) === true) {
            return PositionOwnership::Manual;
        }
        if (($meta['other_ea'] ?? false) === true) {
            return PositionOwnership::OtherEa;
        }

        return PositionOwnership::Unknown;
    }

    public function assertNexaManaged(ManagedPosition $managed): void
    {
        if ($managed->ownership !== PositionOwnership::NexaManaged) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ownership' => 'Foreign/manual/other-EA positions are never auto-managed.',
            ]);
        }
        if ($managed->environment !== TradingEnvironment::Demo) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'environment' => 'Only DEMO managed positions may receive broker-changing actions.',
            ]);
        }
    }

    public function claimFromIntent(TradeIntent $intent, Position $position, string $brokerPositionId): ManagedPosition
    {
        return ManagedPosition::query()->updateOrCreate(
            [
                'broker_account_id' => $intent->broker_account_id,
                'broker_position_id' => $brokerPositionId,
            ],
            [
                'user_id' => $intent->user_id,
                'position_id' => $position->id,
                'trade_intent_id' => $intent->id,
                'risk_decision_id' => $intent->risk_decision_id,
                'ownership' => PositionOwnership::NexaManaged,
                'management_status' => \App\Enums\ManagementStatus::Managing,
                'symbol' => $intent->instrument->symbol ?? $position->instrument?->symbol ?? 'UNKNOWN',
                'broker_symbol' => $intent->instrument->symbol ?? null,
                'direction' => $position->direction,
                'environment' => TradingEnvironment::Demo,
                'initial_volume' => $position->initial_volume,
                'current_volume' => $position->current_volume,
                'entry_price' => $position->average_entry_price,
                'initial_stop_loss' => $position->stop_loss,
                'current_stop_loss' => $position->stop_loss,
                'initial_take_profit' => $position->take_profit,
                'current_take_profit' => $position->take_profit,
                'opened_at' => $position->opened_at ?? now(),
                'last_synced_at' => now(),
                'metadata' => ['origin' => 'NEXA', 'nexa_managed' => true],
            ]
        );
    }
}
