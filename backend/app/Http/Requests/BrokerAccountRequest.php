<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrokerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'broker' => ['nullable', 'string', 'max:255'],
            'platform' => ['sometimes', Rule::in(['NONE', 'SIMULATION'])],
            'server' => ['nullable', 'string', 'max:255'],
            'account_reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'leverage' => ['sometimes', 'integer', 'between:1,5000'],
            'status' => ['sometimes', Rule::in(['DISCONNECTED', 'READY', 'DISABLED'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'risk_profile_id' => ['nullable', 'exists:risk_profiles,id'],
            'metadata' => ['sometimes', 'array'],
            'environment' => ['sometimes', Rule::in(['SIMULATION'])],
            'password' => ['prohibited'],
            'token' => ['prohibited'],
            'api_key' => ['prohibited'],
            'secret' => ['prohibited'],
            'execution_enabled' => ['prohibited'],
            'live_enabled' => ['prohibited'],
        ];
    }
}
