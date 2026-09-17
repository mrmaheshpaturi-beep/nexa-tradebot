<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'timezone:all'],
            'locale' => ['sometimes', Rule::in(['en'])],
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'default_dashboard' => ['sometimes', Rule::in(['overview', 'trading', 'risk'])],
            'favorite_symbols' => ['sometimes', 'array', 'max:20'],
            'favorite_symbols.*' => ['distinct', Rule::in(['XAUUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'NAS100', 'BTCUSD'])],
            'default_timeframe' => ['sometimes', Rule::in(['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'])],
            'table_page_size' => ['sometimes', Rule::in([10, 25, 50, 100])],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
