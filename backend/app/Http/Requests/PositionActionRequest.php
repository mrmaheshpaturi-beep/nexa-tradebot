<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PositionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'volume' => ['nullable', 'numeric', 'gt:0'],
            'stop_loss' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'take_profit' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ];
    }
}
