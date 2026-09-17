<?php

namespace App\Services;

use App\Contracts\PositionReconciliationService;
use App\Enums\DealType;
use App\Enums\PositionStatus;
use App\Models\Position;

class SimulationPositionReconciliationService implements PositionReconciliationService
{
    public function reconcile(Position $position): array
    {
        $entryVolume = (float) $position->deals()->where('type', DealType::Entry)->sum('volume');
        $exitVolume = (float) $position->deals()->whereIn('type', [DealType::Exit, DealType::PartialExit])->sum('volume');
        $expected = max(0, $entryVolume - $exitVolume);
        $issues = [];

        if (abs($expected - (float) $position->current_volume) > 0.00000001) {
            $issues[] = 'Position volume does not match its deals.';
        }
        if ($position->status === PositionStatus::Closed && $expected > 0.00000001) {
            $issues[] = 'Closed position retains deal exposure.';
        }

        return ['consistent' => $issues === [], 'issues' => $issues];
    }
}
