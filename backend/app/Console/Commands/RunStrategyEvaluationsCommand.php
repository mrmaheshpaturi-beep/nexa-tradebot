<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StrategyEngineService;
use Illuminate\Console\Command;

/**
 * Evaluation coordinator — prefers ON_CANDLE_CLOSE idempotent evaluations.
 */
class RunStrategyEvaluationsCommand extends Command
{
    protected $signature = 'strategies:evaluate {--user= : User id} {--prefer=simulation}';

    protected $description = 'Run Phase 7 strategy evaluations (signals only; no broker execution)';

    public function handle(StrategyEngineService $engine): int
    {
        $userId = $this->option('user');
        $prefer = (string) $this->option('prefer');
        $users = $userId
            ? User::query()->whereKey($userId)->get()
            : User::query()->whereHas('strategies', fn ($q) => $q->where('enabled', true)->where('status', 'ACTIVE'))->get();

        foreach ($users as $user) {
            $result = $engine->evaluateAll($user, $prefer);
            $this->info("User {$user->id}: {$result['count']} evaluations");
        }

        $this->info('Done. order_send=false auto_trading=DISABLED');

        return self::SUCCESS;
    }
}
