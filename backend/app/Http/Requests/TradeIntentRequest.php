<?php

namespace App\Http\Requests;

use App\Enums\OrderDirection;
use App\Enums\OrderType;
use App\Enums\TimeInForce;
use App\Enums\TradeOrigin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TradeIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_public_id' => ['required', 'uuid'],
            'instrument_public_id' => ['required', 'uuid'],
            'strategy_id' => ['nullable', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'origin' => ['sometimes', Rule::enum(TradeOrigin::class)],
            'side' => ['required', Rule::enum(OrderDirection::class)],
            'order_type' => ['required', Rule::enum(OrderType::class)],
            'requested_volume' => ['required', 'numeric', 'gt:0'],
            'requested_entry' => ['nullable', 'required_unless:order_type,MARKET', 'numeric', 'gt:0'],
            'stop_loss' => ['nullable', 'numeric', 'gt:0'],
            'take_profit' => ['nullable', 'numeric', 'gt:0'],
            'take_profit_2' => ['nullable', 'numeric', 'gt:0'],
            'time_in_force' => ['sometimes', Rule::enum(TimeInForce::class)],
            'comment' => ['nullable', 'string', 'max:255'],
            'risk_percent' => ['nullable', 'numeric', 'between:0,100'],
            'metadata' => ['sometimes', 'array'],
            'environment' => ['prohibited'],
            'broker_transmitted' => ['prohibited'],
        ];
    }
}
