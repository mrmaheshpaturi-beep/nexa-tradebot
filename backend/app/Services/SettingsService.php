<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    public const SAFETY_DEFAULTS = [
        'trading_enabled' => false,
        'simulation_execution_enabled' => false,
        'auto_trading_enabled' => false,
        'emergency_stop' => true,
        'allow_demo_execution' => false,
        'allow_live_execution' => false,
    ];

    public const GENERAL_DEFAULTS = [
        'application_name' => 'Nexa TradeBot',
        'default_timezone' => 'UTC',
        'default_locale' => 'en',
    ];

    public const SYSTEM_DEFAULTS = [
        'maintenance_mode' => false,
        'market_data_source' => 'MOCK MARKET DATA',
        'simulation_engine' => 'SIMULATION ENGINE',
    ];

    public const LOCKED_FALSE = ['auto_trading_enabled', 'allow_demo_execution', 'allow_live_execution'];

    public function __construct(private readonly AuditService $audit) {}

    public function value(string $key): mixed
    {
        return ApplicationSetting::where('key', $key)->value('value') ?? (self::SAFETY_DEFAULTS[$key] ?? null);
    }

    public function put(string $key, mixed $value, bool $public, Request $request): ApplicationSetting
    {
        if (in_array($key, self::LOCKED_FALSE, true) && $value !== false) {
            throw ValidationException::withMessages(['value' => 'This execution capability is hard-disabled.']);
        }

        return DB::transaction(function () use ($key, $value, $public, $request): ApplicationSetting {
            $setting = ApplicationSetting::firstOrNew(['key' => $key]);
            $before = $setting->exists ? $setting->value : null;
            $group = array_key_exists($key, self::SAFETY_DEFAULTS)
                ? 'trading'
                : (array_key_exists($key, self::SYSTEM_DEFAULTS) ? 'system' : 'general');
            $setting->fill([
                'group' => $group,
                'value' => $value,
                'is_public' => $public,
                'updated_by' => $request->user()->id,
            ])->save();
            $this->audit->record('setting.updated', $setting, ['value' => $before], ['value' => $value], $request);

            return $setting;
        });
    }
}
