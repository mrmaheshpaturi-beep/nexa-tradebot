<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Signal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SimulationOrderService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function create(array $data, Request $request): array
    {
        if ($this->settings->value('emergency_stop') !== false || $this->settings->value('trading_enabled') !== true) {
            throw ValidationException::withMessages(['trading' => 'Simulation trading is stopped.']);
        }

        return DB::transaction(function () use ($data, $request): array {
            $existing = Order::where(function ($query) use ($data, $request): void {
                $query->where(function ($nested) use ($data, $request): void {
                    $nested->where('user_id', $request->user()->id)
                        ->where('idempotency_key', $data['idempotency_key']);
                })->orWhere('command_id', $data['command_id']);
            })
                ->first();
            if ($existing) {
                if ($existing->user_id !== $request->user()->id) {
                    throw ValidationException::withMessages(['command_id' => 'This command identifier is already in use.']);
                }

                return ['order' => $existing, 'replayed' => true];
            }

            if (! empty($data['broker_account_id'])
                && ! $request->user()->brokerAccounts()->whereKey($data['broker_account_id'])->exists()) {
                throw ValidationException::withMessages(['broker_account_id' => 'The selected account is not available to this user.']);
            }
            if (! empty($data['signal_id'])
                && ! Signal::whereKey($data['signal_id'])->whereHas(
                    'strategy',
                    fn ($query) => $query->where('user_id', $request->user()->id),
                )->exists()) {
                throw ValidationException::withMessages(['signal_id' => 'The selected signal is not available to this user.']);
            }

            $order = Order::create([
                ...$data,
                'user_id' => $request->user()->id,
                'status' => 'SIMULATED',
                'environment' => 'SIMULATION',
                'simulated' => true,
                'broker_transmitted' => false,
            ]);
            $this->audit->record('simulation_order.created', $order, [], $order->only([
                'public_id', 'command_id', 'symbol', 'direction', 'volume', 'risk_amount',
                'risk_percent', 'stop_loss', 'take_profit', 'environment', 'simulated', 'broker_transmitted',
            ]), $request);

            return ['order' => $order, 'replayed' => false];
        });
    }
}
