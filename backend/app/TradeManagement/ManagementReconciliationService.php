<?php

namespace App\TradeManagement;

use App\Contracts\DemoBridgeClient;
use App\Enums\ManagementStatus;
use App\Enums\ManualChangeDisposition;
use App\Enums\PositionManagementActionStatus;
use App\Enums\PositionOwnership;
use App\Models\ManagedPosition;
use App\Models\ManagedPositionSnapshot;
use App\Models\PositionManagementAction;
use App\Models\TradeManagementEvent;

class ManagementReconciliationService
{
    public function __construct(
        private readonly DemoBridgeClient $bridge,
        private readonly TradeSummaryService $summaries,
    ) {}

    public function reconcileAction(PositionManagementAction $action): PositionManagementAction
    {
        $positions = $this->bridge->syncPositions();
        $managed = $action->managedPosition;
        $ticket = (string) $managed->broker_position_id;
        $found = null;
        foreach ($positions as $row) {
            if ((string) ($row['ticket'] ?? $row['position_id'] ?? '') === $ticket) {
                $found = $row;
                break;
            }
        }

        ManagedPositionSnapshot::query()->create([
            'managed_position_id' => $managed->id,
            'source' => 'RECONCILE',
            'snapshot' => ['bridge' => $found, 'action' => $action->public_id],
            'captured_at' => now(),
        ]);

        if ($action->action_type->value === 'FULL_CLOSE') {
            if ($found === null) {
                $managed->forceFill([
                    'management_status' => ManagementStatus::Closed,
                    'current_volume' => 0,
                    'closed_at' => now(),
                    'close_reason' => $managed->close_reason ?? 'UNKNOWN',
                    'last_synced_at' => now(),
                ])->save();
                $action->forceFill([
                    'status' => PositionManagementActionStatus::Reconciled,
                    'completed_at' => now(),
                ])->save();
                $this->summaries->finalize($managed);

                return $action->fresh();
            }
        }

        if ($action->action_type->value === 'PARTIAL_CLOSE' && $found) {
            $managed->forceFill([
                'current_volume' => $found['volume'] ?? $managed->current_volume,
                'current_stop_loss' => $found['sl'] ?? $managed->current_stop_loss,
                'current_take_profit' => $found['tp'] ?? $managed->current_take_profit,
                'last_synced_at' => now(),
            ])->save();
            $action->forceFill(['status' => PositionManagementActionStatus::Reconciled, 'completed_at' => now()])->save();

            return $action->fresh();
        }

        if (in_array($action->action_type->value, ['MODIFY_SL', 'MODIFY_TP', 'MODIFY_SL_TP'], true) && $found) {
            $managed->forceFill([
                'current_stop_loss' => $found['sl'] ?? $managed->current_stop_loss,
                'current_take_profit' => $found['tp'] ?? $managed->current_take_profit,
                'current_volume' => $found['volume'] ?? $managed->current_volume,
                'last_synced_at' => now(),
            ])->save();
            $action->forceFill(['status' => PositionManagementActionStatus::Reconciled, 'completed_at' => now()])->save();

            return $action->fresh();
        }

        if ($found === null && $managed->management_status !== ManagementStatus::Closed) {
            $this->detectExternalClose($managed);
        }

        return $action->fresh();
    }

    public function syncManaged(ManagedPosition $managed): ManagedPosition
    {
        if ($managed->ownership !== PositionOwnership::NexaManaged) {
            return $managed;
        }
        $positions = $this->bridge->syncPositions();
        $ticket = (string) $managed->broker_position_id;
        $found = null;
        foreach ($positions as $row) {
            if ((string) ($row['ticket'] ?? $row['position_id'] ?? '') === $ticket) {
                $found = $row;
                break;
            }
        }

        ManagedPositionSnapshot::query()->create([
            'managed_position_id' => $managed->id,
            'source' => 'SYNC',
            'snapshot' => $found ?? ['missing' => true],
            'captured_at' => now(),
        ]);

        if ($found === null) {
            return $this->detectExternalClose($managed);
        }

        $slChanged = isset($found['sl']) && $managed->current_stop_loss !== null
            && abs((float) $found['sl'] - (float) $managed->current_stop_loss) > 1e-8;
        $tpChanged = isset($found['tp']) && $managed->current_take_profit !== null
            && abs((float) $found['tp'] - (float) $managed->current_take_profit) > 1e-8;

        if ($slChanged || $tpChanged) {
            $disposition = $managed->policy?->manual_change_disposition ?? ManualChangeDisposition::RequiresReview;
            TradeManagementEvent::query()->create([
                'managed_position_id' => $managed->id,
                'user_id' => $managed->user_id,
                'environment' => 'DEMO',
                'event_type' => 'MANUAL_MT5_CHANGE',
                'severity' => 'WARN',
                'payload' => [
                    'disposition' => $disposition->value,
                    'bridge' => $found,
                    'previous_sl' => $managed->current_stop_loss,
                    'previous_tp' => $managed->current_take_profit,
                ],
                'occurred_at' => now(),
            ]);
            if ($disposition === ManualChangeDisposition::Adopt) {
                $managed->forceFill([
                    'current_stop_loss' => $found['sl'] ?? $managed->current_stop_loss,
                    'current_take_profit' => $found['tp'] ?? $managed->current_take_profit,
                ])->save();
            } elseif ($disposition === ManualChangeDisposition::RequiresReview) {
                $managed->forceFill(['management_status' => ManagementStatus::RequiresReview])->save();
            }
        }

        $managed->forceFill([
            'current_volume' => $found['volume'] ?? $managed->current_volume,
            'last_synced_at' => now(),
        ])->save();

        return $managed->fresh();
    }

    private function detectExternalClose(ManagedPosition $managed): ManagedPosition
    {
        TradeManagementEvent::query()->create([
            'managed_position_id' => $managed->id,
            'user_id' => $managed->user_id,
            'environment' => 'DEMO',
            'event_type' => 'EXTERNAL_POSITION_CLOSE',
            'severity' => 'WARN',
            'payload' => ['broker_position_id' => $managed->broker_position_id],
            'occurred_at' => now(),
        ]);
        $managed->forceFill([
            'management_status' => ManagementStatus::Closed,
            'current_volume' => 0,
            'closed_at' => now(),
            'close_reason' => 'EXTERNAL_MT5',
            'last_synced_at' => now(),
        ])->save();
        $this->summaries->finalize($managed);

        return $managed->fresh();
    }
}
