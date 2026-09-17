<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimulationOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'command_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'symbol' => ['required', Rule::in(['XAUUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'NAS100', 'BTCUSD'])],
            'direction' => ['required', Rule::in(['BUY', 'SELL'])],
            'volume' => ['required', 'numeric', 'between:0.01,5'],
            'requested_price' => ['nullable', 'numeric', 'gt:0'],
            'risk_amount' => ['nullable', 'numeric', 'min:0'],
            'risk_percent' => ['nullable', 'numeric', 'between:0,10'],
            'stop_loss' => ['nullable', 'numeric', 'gt:0'],
            'take_profit' => ['nullable', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:255'],
            'broker_account_id' => ['nullable', 'exists:broker_accounts,id'],
            'signal_id' => ['nullable', 'exists:signals,id'],
        ];
    }
}
