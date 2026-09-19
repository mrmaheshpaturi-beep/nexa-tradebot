<?php

namespace App\Hardening;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningOpsSafeMode;
use App\Models\User;
use App\Observability\ObservabilityService;
use App\Observability\SystemHealthService;
use Illuminate\Support\Str;

/**
 * Operations Control Center + scoped safe modes.
 * Separates liveness / readiness / trading-readiness for operators.
 */
class OpsControlCenterService
{
    public function __construct(
        private readonly ObservabilityService $obs,
        private readonly SystemHealthService $health,
        private readonly AppBrokerEnvironmentService $envs,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $liveness = ['status' => 'ALIVE', 'meaning' => 'Process up'];
        $env = $this->envs->validate();
        $readiness = [
            'status' => $env['ok'] ? 'READY' : 'NOT_READY',
            'meaning' => 'Config/env validation ok',
            'env' => $env,
        ];
        $trading = $this->obs->tradingReadiness();
        $snapshot = $this->health->evaluate();
        $safeModes = HardeningOpsSafeMode::query()->where('active', true)->orderByDesc('id')->limit(50)->get();

        return [
            'phase' => HardeningSafety::PHASE,
            'hardening_version' => HardeningSafety::HARDENING_VERSION,
            'safety' => HardeningSafety::matrix(),
            'liveness' => $liveness,
            'readiness' => $readiness,
            'trading_readiness' => $trading,
            'separation' => [
                'liveness' => 'process_up',
                'readiness' => 'env_config_ok',
                'trading_readiness' => 'demo_path_only_live_always_not_ready',
            ],
            'health' => [
                'overall' => $snapshot->overall_status,
                'new_entries_blocked' => $snapshot->new_entries_blocked,
                'block_reason' => $snapshot->block_reason,
            ],
            'active_safe_modes' => $safeModes,
            'scoped_safe_mode_kinds' => HardeningSafety::SCOPED_SAFE_MODES,
            'live_auto_exists' => false,
            'order_send_phase19' => 0,
        ];
    }

    public function activateSafeMode(User $user, string $scope, string $reason, ?string $scopeRef = null): HardeningOpsSafeMode
    {
        $scope = strtoupper($scope);
        if (! in_array($scope, HardeningSafety::SCOPED_SAFE_MODES, true)) {
            throw new \InvalidArgumentException('Invalid safe mode scope');
        }

        return HardeningOpsSafeMode::query()->create([
            'public_id' => (string) Str::uuid(),
            'scope' => $scope,
            'scope_ref' => $scopeRef,
            'active' => true,
            'reason' => $reason,
            'activated_by' => $user->id,
            'activated_at' => now(),
            'metadata' => [
                'phase' => HardeningSafety::PHASE,
                'entries_blocked' => true,
                'order_send' => false,
            ],
        ]);
    }

    public function clearSafeMode(string $publicId): HardeningOpsSafeMode
    {
        $row = HardeningOpsSafeMode::query()->where('public_id', $publicId)->firstOrFail();
        $row->forceFill([
            'active' => false,
            'cleared_at' => now(),
        ])->save();

        return $row;
    }

    /** @return array<string, mixed> */
    public function tradingReadinessSeparated(): array
    {
        $payload = $this->obs->tradingReadiness();

        return [
            'liveness' => 'ALIVE',
            'readiness' => $this->envs->validate()['ok'] ? 'READY' : 'NOT_READY',
            'trading_readiness' => $payload,
            'live' => 'NOT_READY',
            'live_auto' => 'DOES_NOT_EXIST',
            'note' => 'Liveness ≠ readiness ≠ trading-readiness. LIVE always NOT_READY.',
        ];
    }
}
