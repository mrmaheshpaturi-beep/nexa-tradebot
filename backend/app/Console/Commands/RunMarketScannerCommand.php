<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MarketScannerEngineService;
use App\Services\SignalOrchestratorService;
use Illuminate\Console\Command;

/**
 * Phase 8 scanner coordinator — ON_INTERVAL / ON_CANDLE_CLOSE entrypoint.
 * Never sends broker orders.
 */
class RunMarketScannerCommand extends Command
{
    protected $signature = 'scanner:run
        {--user= : User id}
        {--trigger=ON_INTERVAL : MANUAL|ON_INTERVAL|ON_CANDLE_CLOSE}
        {--prefer=simulation}
        {--config= : Scanner config id}';

    protected $description = 'Run Phase 8 market scanner (candidates only; no broker execution)';

    public function handle(MarketScannerEngineService $scanner, SignalOrchestratorService $orchestrator): int
    {
        $userId = $this->option('user');
        $trigger = strtoupper((string) $this->option('trigger'));
        $prefer = (string) $this->option('prefer');
        $configId = $this->option('config');

        $users = $userId
            ? User::query()->whereKey($userId)->get()
            : User::query()->whereHas('strategies', fn ($q) => $q->where('enabled', true))->orWhereHas('preference')->limit(50)->get();

        if ($users->isEmpty()) {
            $users = User::query()->limit(5)->get();
        }

        foreach ($users as $user) {
            $orchestrator->expireDue($user);
            $result = $scanner->runScan($user, [
                'trigger' => $trigger,
                'prefer' => $prefer,
                'config_id' => $configId ? (int) $configId : null,
            ]);
            $run = $result['run'] ?? null;
            $this->info(sprintf(
                'User %d: ok=%s idempotent=%s candidates_created=%s',
                $user->id,
                ! empty($result['ok']) ? 'yes' : 'no',
                ! empty($result['idempotent']) ? 'yes' : 'no',
                is_object($run) ? $run->candidates_created : ($result['orchestrator']['created'] ?? 0),
            ));
        }

        $this->info('Done. order_send=false broker_routing=false');

        return self::SUCCESS;
    }
}
