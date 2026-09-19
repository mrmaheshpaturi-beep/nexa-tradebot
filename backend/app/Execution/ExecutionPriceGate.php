<?php

namespace App\Execution;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Models\TradeIntent;
use App\Models\TradingInstrument;
use Illuminate\Validation\ValidationException;

/**
 * Price / stop / TP / volume gates before DEMO submit.
 */
class ExecutionPriceGate
{
    /**
     * @param  array{bid:float|string,ask:float|string}  $quote
     * @param  array<string,mixed>  $spec
     */
    public function assertPlaceOrder(TradeIntent $intent, array $quote, array $spec, float $volume): void
    {
        $instrument = $intent->instrument;
        $side = $intent->side instanceof OrderDirection ? $intent->side : OrderDirection::from($intent->side);
        $orderType = $intent->order_type instanceof OrderType ? $intent->order_type : OrderType::from($intent->order_type);
        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];
        $errors = [];

        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            $errors['quote'] = 'Fresh quote failed price gate.';
        }

        $minVol = (float) ($spec['volume_min'] ?? $instrument->minimum_volume);
        $maxVol = (float) ($spec['volume_max'] ?? $instrument->maximum_volume);
        $step = (float) ($spec['volume_step'] ?? $instrument->step_volume);
        if ($volume < $minVol || $volume > $maxVol) {
            $errors['volume'] = 'Volume is outside DEMO symbol bounds.';
        }
        if ($step > 0) {
            $steps = round($volume / $step);
            if (abs(($steps * $step) - $volume) > 1e-8) {
                $errors['volume_step'] = 'Volume does not match DEMO volume step.';
            }
        }

        $entry = match ($orderType) {
            OrderType::Market => $side === OrderDirection::Buy ? $ask : $bid,
            default => (float) ($intent->requested_entry ?? $intent->requested_price ?? 0),
        };
        if ($entry <= 0) {
            $errors['price'] = 'Entry price failed DEMO price gate.';
        }

        $sl = $intent->stop_loss !== null ? (float) $intent->stop_loss : null;
        $tp = $intent->take_profit !== null ? (float) $intent->take_profit : null;
        $minDist = (float) ($spec['trade_stops_level'] ?? $instrument->minimum_stop_distance ?? 0);
        $point = (float) ($spec['point'] ?? $instrument->point_size ?? 0.00001);

        if ($sl !== null) {
            $validSl = $side === OrderDirection::Buy ? $sl < $entry : $sl > $entry;
            if (! $validSl) {
                $errors['stop_loss'] = 'Stop loss side geometry failed DEMO gate.';
            }
            if ($minDist > 0 && abs($entry - $sl) < ($minDist * $point)) {
                $errors['stop_loss_distance'] = 'Stop loss is closer than minimum DEMO stop distance.';
            }
        }
        if ($tp !== null) {
            $validTp = $side === OrderDirection::Buy ? $tp > $entry : $tp < $entry;
            if (! $validTp) {
                $errors['take_profit'] = 'Take profit side geometry failed DEMO gate.';
            }
            if ($minDist > 0 && abs($entry - $tp) < ($minDist * $point)) {
                $errors['take_profit_distance'] = 'Take profit is closer than minimum DEMO stop distance.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
