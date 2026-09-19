<?php

namespace App\TradeManagement;

use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementDecisionType;
use App\Models\ManagedPosition;
use App\Models\TradeManagementDecision;
use App\Models\User;

/** DEMO-only partial close facade (Phase 11 §35). */
class PartialCloseService
{
    public function __construct(private readonly ManagementActionService $actions) {}

    public function close(ManagedPosition $position, User $user, float $volume, string $idempotencyKey): array
    {
        $decision = TradeManagementDecision::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $user->id,
            'decision_type' => ManagementDecisionType::PartialClose,
            'status' => ManagementDecisionStatus::Approved,
            'rule_code' => 'PARTIAL_CLOSE_SERVICE',
            'priority' => 50,
            'why' => 'PartialCloseService DEMO close.',
            'proposed_close_volume' => $volume,
            'payload' => ['protective' => false, 'close_reason' => 'TAKE_PROFIT'],
            'decided_at' => now(),
        ]);

        return $this->actions->executeDecision($decision, $user, $idempotencyKey);
    }
}
