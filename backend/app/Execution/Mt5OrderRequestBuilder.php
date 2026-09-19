<?php

namespace App\Execution;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Models\ExecutionCommand;
use App\Models\TradeIntent;
use Illuminate\Validation\ValidationException;

/**
 * Centralized MT5 request builder for DEMO submissions.
 */
class Mt5OrderRequestBuilder
{
    /**
     * @param  array{bid:float|string,ask:float|string,digits?:int}  $quote
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    public function buildPlaceOrder(
        TradeIntent $intent,
        ExecutionCommand $command,
        array $quote,
        array $spec,
        array $verification,
    ): array {
        $side = $intent->side instanceof OrderDirection ? $intent->side : OrderDirection::from($intent->side);
        $orderType = $intent->order_type instanceof OrderType ? $intent->order_type : OrderType::from($intent->order_type);
        $symbol = $intent->instrument->symbol;
        $volume = (float) ($command->volume ?? $intent->requested_volume);
        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];

        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw ValidationException::withMessages(['quote' => 'Fresh quote is invalid for DEMO request build.']);
        }

        $price = match ($orderType) {
            OrderType::Market => $side === OrderDirection::Buy ? $ask : $bid,
            default => (float) ($intent->requested_entry ?? $intent->requested_price ?? 0),
        };

        if ($price <= 0) {
            throw ValidationException::withMessages(['price' => 'A valid price is required for the DEMO order request.']);
        }

        return [
            'action' => 'PLACE_ORDER',
            'symbol' => $symbol,
            'side' => $side->value,
            'order_type' => $orderType->value,
            'volume' => number_format($volume, 4, '.', ''),
            'price' => number_format($price, (int) ($spec['digits'] ?? $quote['digits'] ?? 5), '.', ''),
            'stop_loss' => $intent->stop_loss !== null ? (string) $intent->stop_loss : null,
            'take_profit' => $intent->take_profit !== null ? (string) $intent->take_profit : null,
            'deviation' => 20,
            'magic' => 10010,
            'comment' => substr((string) ($intent->comment ?? 'NEXA-DEMO'), 0, 31),
            'type_filling' => 'IOC',
            'account' => [
                'login' => $verification['login'],
                'server' => $verification['server'],
                'trade_mode' => $verification['trade_mode'],
            ],
            'command_public_id' => $command->public_id,
            'intent_public_id' => $intent->public_id,
        ];
    }
}
