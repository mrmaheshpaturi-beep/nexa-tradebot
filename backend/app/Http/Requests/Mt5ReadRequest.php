<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Mt5ReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('symbol')) {
            $this->merge(['symbol' => strtoupper(trim((string) $this->route('symbol')))]);
        }
    }

    public function rules(): array
    {
        return [
            'symbol' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Z0-9.]+$/'],
            'timeframe' => ['sometimes', Rule::in(['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'])],
            'count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after:date_from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('trading_bridge.history_max_records', 500)],
        ];
    }
}
