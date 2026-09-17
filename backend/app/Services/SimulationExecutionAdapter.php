<?php

namespace App\Services;

use App\Contracts\ExecutionAdapter;
use App\Contracts\MarketDataProvider;
use App\Enums\ExecutionCommandType;
use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Models\ExecutionCommand;
use DomainException;

class SimulationExecutionAdapter implements ExecutionAdapter
{
    public function __construct(private readonly MarketDataProvider $marketData) {}

    public function execute(ExecutionCommand $command): array
    {
        if ($command->environment->value !== 'SIMULATION') {
            throw new DomainException('The simulation adapter rejects non-simulation commands.');
        }

        if (in_array($command->type, [
            ExecutionCommandType::ModifyOrder,
            ExecutionCommandType::CancelOrder,
            ExecutionCommandType::ModifyPositionStopLoss,
            ExecutionCommandType::ModifyPositionTakeProfit,
        ], true)) {
            return ['accepted' => true, 'fill_price' => null, 'filled_at' => null, 'reason' => null];
        }

        if ($command->type === ExecutionCommandType::PlaceOrder) {
            $command->loadMissing('tradeIntent.instrument');
            $intent = $command->tradeIntent;
            if ($intent->order_type !== OrderType::Market) {
                return ['accepted' => true, 'fill_price' => null, 'filled_at' => null, 'reason' => null];
            }
            $symbol = $intent->instrument->symbol;
            $side = $intent->side;
        } else {
            $command->loadMissing('position.instrument');
            $symbol = $command->position->instrument->symbol;
            $side = $command->position->direction === OrderDirection::Buy ? OrderDirection::Sell : OrderDirection::Buy;
        }

        $quote = $this->marketData->getQuote($symbol);

        return [
            'accepted' => true,
            'fill_price' => $side === OrderDirection::Buy ? $quote['ask'] : $quote['bid'],
            'filled_at' => now()->utc()->toIso8601String(),
            'reason' => null,
        ];
    }
}
