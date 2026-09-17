<?php

namespace App\Services;

use App\Models\AccountSnapshot;
use App\Models\BrokerAccount;

class AccountStateUpdater
{
    public function __construct(private readonly AccountStateCalculator $calculator) {}

    public function capture(BrokerAccount $account, float $realizedAdjustment = 0): AccountSnapshot
    {
        return $account->snapshots()->create([
            ...$this->calculator->calculate($account, $realizedAdjustment),
            'captured_at' => now(),
        ]);
    }
}
