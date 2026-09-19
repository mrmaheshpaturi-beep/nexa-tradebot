<?php

namespace App\Automation;

use App\Enums\AutomationProfileStatus;
use App\Models\AutomationProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomationProfileService
{
    /**
     * @param  array<string,mixed>  $input
     */
    public function create(User $user, array $input): AutomationProfile
    {
        $symbols = array_values(array_unique(array_map('strtoupper', $input['symbol_universe'] ?? ['EURUSD'])));
        $tfs = array_values(array_unique($input['timeframe_universe'] ?? ['M15', 'H1']));
        $matrix = $input['strategy_matrix'] ?? [
            ['symbol' => 'EURUSD', 'timeframe' => 'H1', 'strategy_key' => 'ema_trend', 'strategy_version' => 'v1'],
        ];

        $payload = [
            'name' => (string) ($input['name'] ?? 'Default DEMO Profile'),
            'version' => 1,
            'status' => AutomationProfileStatus::Draft,
            'symbol_universe' => $symbols,
            'timeframe_universe' => $tfs,
            'strategy_matrix' => $matrix,
            'qualification_rules' => $input['qualification_rules'] ?? [
                'min_confluence' => 60,
                'intelligence_required' => true,
                'ai_failure_policy' => 'WAIT', // WAIT|EXPIRE|REJECT — never silent bypass
                'ai_wait_seconds' => 120,
            ],
            'risk_overrides' => $input['risk_overrides'] ?? [],
            'reentry_policy' => $input['reentry_policy'] ?? [
                'allow_same_direction_reentry' => false,
                'cooldown_after_loss_seconds' => 900,
                'cooldown_after_close_seconds' => 300,
                'conservative_default' => true,
            ],
            'session_policy' => $input['session_policy'] ?? [
                'trade_weekends' => false,
                'respect_symbol_hours' => true,
                'respect_dst' => true,
            ],
            'calendar_policy' => $input['calendar_policy'] ?? [
                'require_calendar' => false,
                'fail_closed_if_unavailable' => true,
                'block_high_impact_minutes_before' => 30,
                'block_high_impact_minutes_after' => 15,
            ],
            'intelligence_required' => (bool) ($input['intelligence_required'] ?? true),
            'closed_candle_only' => (bool) ($input['closed_candle_only'] ?? true),
            'max_open_positions' => (int) ($input['max_open_positions'] ?? 3),
            'max_trades_per_day' => (int) ($input['max_trades_per_day'] ?? 10),
            'max_trades_per_symbol_per_day' => (int) ($input['max_trades_per_symbol_per_day'] ?? 3),
            'loss_streak_lock' => (int) ($input['loss_streak_lock'] ?? 3),
            'cooldown_seconds' => (int) ($input['cooldown_seconds'] ?? 300),
            'signal_ttl_seconds' => (int) ($input['signal_ttl_seconds'] ?? 600),
            'allow_revenge_trading' => false,
            'allow_martingale' => false,
            'online_self_optimization' => false,
        ];
        $payload['config_hash'] = hash('sha256', json_encode($this->hashable($payload)));

        return AutomationProfile::query()->create([
            ...$payload,
            'user_id' => $user->id,
        ]);
    }

    public function validate(AutomationProfile $profile): AutomationProfile
    {
        if ($profile->status === AutomationProfileStatus::Archived) {
            throw ValidationException::withMessages(['profile' => 'Archived profiles cannot be validated.']);
        }
        if ($profile->allow_revenge_trading || $profile->allow_martingale || $profile->online_self_optimization) {
            throw ValidationException::withMessages(['profile' => 'Revenge/martingale/self-optimization are forbidden.']);
        }
        if ($profile->symbol_universe === [] || $profile->strategy_matrix === []) {
            throw ValidationException::withMessages(['profile' => 'Universe and strategy matrix required.']);
        }
        $profile->forceFill([
            'status' => AutomationProfileStatus::Validated,
            'validated_at' => now(),
            'config_hash' => hash('sha256', json_encode($this->hashable($profile->toArray()))),
        ])->save();

        return $profile->fresh();
    }

    public function activate(AutomationProfile $profile): AutomationProfile
    {
        if ($profile->status !== AutomationProfileStatus::Validated
            && $profile->status !== AutomationProfileStatus::Active) {
            throw ValidationException::withMessages(['profile' => 'Profile must be VALIDATED before activate.']);
        }

        return DB::transaction(function () use ($profile): AutomationProfile {
            AutomationProfile::query()
                ->where('user_id', $profile->user_id)
                ->where('status', AutomationProfileStatus::Active)
                ->where('id', '!=', $profile->id)
                ->update(['status' => AutomationProfileStatus::Validated->value]);

            $profile->forceFill([
                'status' => AutomationProfileStatus::Active,
                'activated_at' => now(),
            ])->save();

            return $profile->fresh();
        });
    }

    /**
     * Profile changes require pause → validate → activate → resume.
     *
     * @param  array<string,mixed>  $input
     */
    public function revise(AutomationProfile $profile, array $input): AutomationProfile
    {
        if ($profile->status === AutomationProfileStatus::Active) {
            throw ValidationException::withMessages([
                'profile' => 'Pause automation and revise as DRAFT/VALIDATED before activate.',
            ]);
        }
        $profile->fill([
            'name' => $input['name'] ?? $profile->name,
            'symbol_universe' => $input['symbol_universe'] ?? $profile->symbol_universe,
            'timeframe_universe' => $input['timeframe_universe'] ?? $profile->timeframe_universe,
            'strategy_matrix' => $input['strategy_matrix'] ?? $profile->strategy_matrix,
            'qualification_rules' => $input['qualification_rules'] ?? $profile->qualification_rules,
            'risk_overrides' => $input['risk_overrides'] ?? $profile->risk_overrides,
            'reentry_policy' => $input['reentry_policy'] ?? $profile->reentry_policy,
            'session_policy' => $input['session_policy'] ?? $profile->session_policy,
            'calendar_policy' => $input['calendar_policy'] ?? $profile->calendar_policy,
            'intelligence_required' => $input['intelligence_required'] ?? $profile->intelligence_required,
            'closed_candle_only' => $input['closed_candle_only'] ?? $profile->closed_candle_only,
            'max_open_positions' => $input['max_open_positions'] ?? $profile->max_open_positions,
            'max_trades_per_day' => $input['max_trades_per_day'] ?? $profile->max_trades_per_day,
            'max_trades_per_symbol_per_day' => $input['max_trades_per_symbol_per_day'] ?? $profile->max_trades_per_symbol_per_day,
            'loss_streak_lock' => $input['loss_streak_lock'] ?? $profile->loss_streak_lock,
            'cooldown_seconds' => $input['cooldown_seconds'] ?? $profile->cooldown_seconds,
            'signal_ttl_seconds' => $input['signal_ttl_seconds'] ?? $profile->signal_ttl_seconds,
            'allow_revenge_trading' => false,
            'allow_martingale' => false,
            'online_self_optimization' => false,
            'version' => $profile->version + 1,
            'status' => AutomationProfileStatus::Draft,
            'validated_at' => null,
            'activated_at' => null,
        ]);
        $profile->config_hash = hash('sha256', json_encode($this->hashable($profile->toArray())));
        $profile->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashable(array $payload): array
    {
        unset($payload['id'], $payload['public_id'], $payload['created_at'], $payload['updated_at'], $payload['user_id'], $payload['config_hash'], $payload['validated_at'], $payload['activated_at']);
        ksort($payload);

        return $payload;
    }
}
