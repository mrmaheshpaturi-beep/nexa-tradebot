<?php

namespace App\Contracts;

use App\Models\Position;

interface PositionReconciliationService
{
    /** @return array{consistent:bool,issues:array<int, string>} */
    public function reconcile(Position $position): array;
}
