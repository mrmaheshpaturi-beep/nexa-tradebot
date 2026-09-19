<?php

namespace App\Services;

use App\Enums\RiskReservationStatus;
use App\Models\BrokerAccount;
use App\Models\RiskDecision;
use App\Models\RiskReservation;
use App\Models\TradeIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RiskReservationService
{
    /**
     * Idempotent margin/exposure reservation for concurrent intent protection.
     *
     * @param  array{reserved_margin:float,reserved_risk:float,reserved_exposure:float,symbol?:string}  $amounts
     */
    public function reserve(
        User $user,
        BrokerAccount $account,
        TradeIntent $intent,
        string $idempotencyKey,
        array $amounts,
        ?RiskDecision $decision = null,
        ?int $ttlSeconds = 900,
    ): RiskReservation {
        return DB::transaction(function () use ($user, $account, $intent, $idempotencyKey, $amounts, $decision, $ttlSeconds): RiskReservation {
            $existing = RiskReservation::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            return RiskReservation::query()->create([
                'user_id' => $user->id,
                'broker_account_id' => $account->id,
                'trade_intent_id' => $intent->id,
                'risk_decision_id' => $decision?->id,
                'idempotency_key' => $idempotencyKey,
                'status' => RiskReservationStatus::Active,
                'reserved_margin' => $amounts['reserved_margin'] ?? 0,
                'reserved_risk' => $amounts['reserved_risk'] ?? 0,
                'reserved_exposure' => $amounts['reserved_exposure'] ?? 0,
                'symbol' => $amounts['symbol'] ?? $intent->instrument?->symbol,
                'metadata' => ['engine' => 'RiskEngine/v1'],
                'expires_at' => $ttlSeconds ? now()->addSeconds($ttlSeconds) : null,
            ]);
        });
    }

    public function release(RiskReservation $reservation): RiskReservation
    {
        if ($reservation->status !== RiskReservationStatus::Active) {
            return $reservation;
        }
        $reservation->update([
            'status' => RiskReservationStatus::Released,
            'released_at' => now(),
        ]);

        return $reservation->fresh();
    }

    public function consume(RiskReservation $reservation): RiskReservation
    {
        if ($reservation->status !== RiskReservationStatus::Active) {
            return $reservation;
        }
        $reservation->update([
            'status' => RiskReservationStatus::Consumed,
            'released_at' => now(),
        ]);

        return $reservation->fresh();
    }

    public function expireDue(): int
    {
        return RiskReservation::query()
            ->where('status', RiskReservationStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => RiskReservationStatus::Expired->value,
                'released_at' => now(),
            ]);
    }
}
