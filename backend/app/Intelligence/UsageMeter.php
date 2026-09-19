<?php

namespace App\Intelligence;

use App\Models\IntelligenceUsageMeter;
use App\Models\User;

class UsageMeter
{
    public function consume(User $user, string $meterKey, int $amount = 1, ?int $budget = null): bool
    {
        $period = (int) date('Ym');
        $row = IntelligenceUsageMeter::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'meter_key' => $meterKey,
                'period_yyyymm' => $period,
            ],
            [
                'used' => 0,
                'budget' => $budget ?? $this->defaultBudget($meterKey),
            ]
        );

        if ($row->used + $amount > $row->budget) {
            return false;
        }

        $row->used += $amount;
        $row->save();

        return true;
    }

    /** @return list<IntelligenceUsageMeter> */
    public function listFor(User $user): array
    {
        return IntelligenceUsageMeter::query()
            ->where('user_id', $user->id)
            ->orderByDesc('period_yyyymm')
            ->get()
            ->all();
    }

    private function defaultBudget(string $key): int
    {
        return match ($key) {
            'ai_calls' => 500,
            'news_calls' => 2000,
            'calendar_calls' => 2000,
            'assessments' => 2000,
            default => 1000,
        };
    }
}
