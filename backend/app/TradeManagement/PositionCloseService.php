<?php

namespace App\TradeManagement;

use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementDecision;
use App\Models\User;

/** DEMO-only full close facade (Phase 11 §42). */
class PositionCloseService
{
    public function __construct(private readonly ManagementActionService $actions) {}

    public function close(ManagedPosition $position, User $user, string $idempotencyKey, string $reason = 'MANUAL'): array
    {
        $decision = TradeManagementDecision::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $user->id,
            'decision_type' => ManagementDecisionType::FullClose,
            'status' => ManagementDecisionStatus::Approved,
            'rule_code' => 'POSITION_CLOSE_SERVICE',
            'priority' => 15,
            'why' => 'PositionCloseService DEMO full close.',
            'proposed_close_volume' => (float) $position->current_volume,
            'payload' => ['protective' => true, 'close_reason' => $reason],
            'decided_at' => now(),
        ]);

        return $this->actions->executeDecision($decision, $user, $idempotencyKey);
    }
}
