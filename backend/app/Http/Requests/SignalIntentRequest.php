<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Enums\TimeInForce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignalIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_public_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'order_type' => ['sometimes', Rule::enum(OrderType::class)],
            'requested_volume' => ['required', 'numeric', 'gt:0'],
            'requested_entry' => ['nullable', 'numeric', 'gt:0'],
            'stop_loss' => ['nullable', 'numeric', 'gt:0'],
            'take_profit' => ['nullable', 'numeric', 'gt:0'],
            'take_profit_2' => ['nullable', 'numeric', 'gt:0'],
            'time_in_force' => ['sometimes', Rule::enum(TimeInForce::class)],
            'comment' => ['nullable', 'string', 'max:255'],
            'risk_percent' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
