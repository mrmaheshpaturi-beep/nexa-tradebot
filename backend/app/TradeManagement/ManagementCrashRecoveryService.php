<?php

namespace App\TradeManagement;

use App\Enums\PositionManagementActionStatus;
use App\Models\PositionManagementAction;

class ManagementCrashRecoveryService
{
    public function __construct(private readonly ManagementReconciliationService $reconciliation) {}

    /** Restart recovery: UNKNOWN actions reconcile first — never blind retry. */
    public function recoverUnknown(): array
    {
        $actions = PositionManagementAction::query()
            ->where('status', PositionManagementActionStatus::TimeoutUnknown->value)
            ->where('blind_retry_forbidden', true)
            ->limit(50)
            ->get();

        $results = [];
        foreach ($actions as $action) {
            $results[] = $this->reconciliation->reconcileAction($action);
        }

        return $results;
    }

    public function recoverOne(PositionManagementAction $action): PositionManagementAction
    {
        if ($action->status !== PositionManagementActionStatus::TimeoutUnknown) {
            return $action;
        }

        return $this->reconciliation->reconcileAction($action);
    }
}
