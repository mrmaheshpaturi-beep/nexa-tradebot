<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE', 'ARCHIVED'])],
            'is_default' => ['sometimes', 'boolean'],
            'max_risk_per_trade' => ['required', 'numeric', 'between:0.01,10'],
            'max_lot_size' => ['required', 'numeric', 'between:0.01,100'],
            'max_daily_loss' => ['required', 'numeric', 'between:0.01,100'],
            'max_weekly_loss' => ['required', 'numeric', 'between:0.01,100'],
            'max_drawdown' => ['required', 'numeric', 'between:0.01,100'],
            'max_open_positions' => ['required', 'integer', 'between:1,100'],
            'max_open_risk' => ['required', 'numeric', 'between:0.01,100'],
            'max_trades_per_day' => ['required', 'integer', 'between:1,1000'],
            'max_consecutive_losses' => ['required', 'integer', 'between:1,100'],
            'min_margin_level' => ['required', 'numeric', 'between:100,10000'],
            'max_spread' => ['required', 'numeric', 'between:0,1000'],
            'max_slippage' => ['required', 'numeric', 'between:0,1000'],
            'min_reward_risk' => ['required', 'numeric', 'between:1,10'],
            'max_correlated_exposure' => ['sometimes', 'numeric', 'between:0.01,100'],
            'atr_stop_multiplier' => ['nullable', 'numeric', 'between:0.1,20'],
            'require_stop_loss' => ['sometimes', 'boolean'],
            'sizing_enabled' => ['sometimes', 'boolean'],
            'session_allowlist' => ['sometimes', 'array'],
            'session_allowlist.*' => ['string', 'max:40'],
            'rule_config' => ['sometimes', 'array'],
            'rules_bundle_version' => ['sometimes', 'string', 'max:32'],
        ];
    }
}
