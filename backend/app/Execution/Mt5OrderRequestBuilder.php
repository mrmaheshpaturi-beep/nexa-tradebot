<?php

namespace App\Execution;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Models\ExecutionCommand;
use App\Models\TradeIntent;
use Illuminate\Validation\ValidationException;

/**
 * Centralized MT5 request builder for DEMO submissions and management actions.
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

    /**
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    public function buildModifyProtection(
        string $symbol,
        string $positionId,
        string $side,
        ?float $sl,
        ?float $tp,
        array $verification,
        string $actionPublicId,
    ): array {
        return [
            'action' => 'MODIFY_POSITION_PROTECTION',
            'symbol' => $symbol,
            'position_id' => $positionId,
            'broker_position_id' => $positionId,
            'side' => $side,
            'stop_loss' => $sl !== null ? (string) $sl : null,
            'take_profit' => $tp !== null ? (string) $tp : null,
            'magic' => 10010,
            'comment' => 'NEXA-MGMT',
            'account' => [
                'login' => $verification['login'],
                'server' => $verification['server'],
                'trade_mode' => $verification['trade_mode'],
            ],
            'action_public_id' => $actionPublicId,
        ];
    }

    /**
     * @param  array<string,mixed>  $verification
     * @param  array{bid:float|string,ask:float|string}  $quote
     * @return array<string,mixed>
     */
    public function buildClosePosition(
        string $symbol,
        string $positionId,
        string $side,
        float $volume,
        array $quote,
        array $verification,
        string $actionPublicId,
        bool $partial = false,
    ): array {
        $dir = OrderDirection::from($side);
        $price = $dir === OrderDirection::Buy ? (float) $quote['bid'] : (float) $quote['ask'];

        return [
            'action' => $partial ? 'PARTIAL_CLOSE' : 'CLOSE_POSITION',
            'symbol' => $symbol,
            'position_id' => $positionId,
            'broker_position_id' => $positionId,
            'side' => $side,
            'volume' => number_format($volume, 4, '.', ''),
            'close_volume' => number_format($volume, 4, '.', ''),
            'price' => number_format($price, 5, '.', ''),
            'magic' => 10010,
            'comment' => $partial ? 'NEXA-PARTIAL' : 'NEXA-CLOSE',
            'account' => [
                'login' => $verification['login'],
                'server' => $verification['server'],
                'trade_mode' => $verification['trade_mode'],
            ],
            'action_public_id' => $actionPublicId,
        ];
    }

    /**
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    public function buildCancelPending(string $orderId, array $verification, string $actionPublicId): array
    {
        return [
            'action' => 'CANCEL_PENDING',
            'order_id' => $orderId,
            'magic' => 10010,
            'comment' => 'NEXA-CANCEL',
            'account' => [
                'login' => $verification['login'],
                'server' => $verification['server'],
                'trade_mode' => $verification['trade_mode'],
            ],
            'action_public_id' => $actionPublicId,
        ];
    }
}
