<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $strategyId = $this->route('strategy')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:255', Rule::unique('trading_strategies')->ignore($strategyId)],
            'category' => ['required', Rule::in(['TREND', 'MOMENTUM', 'REVERSAL', 'BREAKOUT', 'CUSTOM'])],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'ACTIVE', 'ARCHIVED'])],
            'mode' => ['sometimes', Rule::in(['MANUAL', 'SIGNAL_ONLY'])],
            'minimum_signal_score' => ['required', 'numeric', 'between:0,100'],
            'risk_profile_id' => ['nullable', 'exists:risk_profiles,id'],
            'symbols' => ['required', 'array', 'min:1', 'max:20'],
            'symbols.*' => ['distinct', Rule::in(['XAUUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'NAS100', 'BTCUSD'])],
            'timeframes' => ['required', 'array', 'min:1'],
            'timeframes.*' => ['distinct', Rule::in(['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'])],
            'sessions' => ['sometimes', 'array'],
            'sessions.*' => ['distinct', Rule::in(['ASIA', 'LONDON', 'NEW_YORK'])],
            'parameters' => ['sometimes', 'array'],
            'enabled' => ['sometimes', 'boolean'],
            'auto_trading_enabled' => ['prohibited'],
            'auto_simulation' => ['sometimes', 'boolean'],
            'plugin_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'evaluation_mode' => ['sometimes', Rule::in(['ON_CANDLE_CLOSE', 'MANUAL'])],
            'higher_timeframes' => ['sometimes', 'array'],
            'higher_timeframes.*' => ['distinct', Rule::in(['M5', 'M15', 'M30', 'H1', 'H4', 'D1'])],
            'configuration' => ['sometimes', 'array'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
