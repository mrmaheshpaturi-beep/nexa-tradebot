<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskProfile extends BaseModel
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'require_stop_loss' => 'boolean',
            'sizing_enabled' => 'boolean',
            'rule_config' => 'array',
            'session_allowlist' => 'array',
            'max_risk_per_trade' => 'decimal:4',
            'max_lot_size' => 'decimal:4',
            'max_daily_loss' => 'decimal:4',
            'max_weekly_loss' => 'decimal:4',
            'max_drawdown' => 'decimal:4',
            'max_open_risk' => 'decimal:4',
            'max_correlated_exposure' => 'decimal:4',
            'min_margin_level' => 'decimal:2',
            'max_spread' => 'decimal:2',
            'max_slippage' => 'decimal:2',
            'min_reward_risk' => 'decimal:4',
            'atr_stop_multiplier' => 'decimal:4',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $profile): void {
            $profile->version = $profile->version ?: 1;
            $profile->rules_bundle_version = $profile->rules_bundle_version ?: 'risk-rules/v1';
            $profile->config_hash = $profile->configHash();
        });
        static::updating(function (self $profile): void {
            if ($profile->isDirty([
                'max_risk_per_trade', 'max_lot_size', 'max_daily_loss', 'max_weekly_loss', 'max_drawdown',
                'max_open_positions', 'max_open_risk', 'max_correlated_exposure', 'max_trades_per_day',
                'max_consecutive_losses', 'min_margin_level', 'max_spread', 'max_slippage', 'min_reward_risk',
                'atr_stop_multiplier', 'require_stop_loss', 'sizing_enabled', 'rule_config', 'session_allowlist',
                'rules_bundle_version', 'status',
            ])) {
                $profile->version = ((int) $profile->getOriginal('version')) + 1;
            }
            $profile->config_hash = $profile->configHash();
        });
    }

    public function configHash(): string
    {
        $payload = [
            'version' => (int) ($this->version ?? 1),
            'rules_bundle_version' => $this->rules_bundle_version ?? 'risk-rules/v1',
            'max_risk_per_trade' => (string) $this->max_risk_per_trade,
            'max_lot_size' => (string) $this->max_lot_size,
            'max_daily_loss' => (string) $this->max_daily_loss,
            'max_weekly_loss' => (string) $this->max_weekly_loss,
            'max_drawdown' => (string) $this->max_drawdown,
            'max_open_positions' => (int) $this->max_open_positions,
            'max_open_risk' => (string) $this->max_open_risk,
            'max_correlated_exposure' => (string) ($this->max_correlated_exposure ?? 4),
            'max_trades_per_day' => (int) $this->max_trades_per_day,
            'max_consecutive_losses' => (int) $this->max_consecutive_losses,
            'min_margin_level' => (string) $this->min_margin_level,
            'max_spread' => (string) $this->max_spread,
            'max_slippage' => (string) $this->max_slippage,
            'min_reward_risk' => (string) $this->min_reward_risk,
            'atr_stop_multiplier' => $this->atr_stop_multiplier,
            'require_stop_loss' => (bool) ($this->require_stop_loss ?? true),
            'sizing_enabled' => (bool) ($this->sizing_enabled ?? true),
            'rule_config' => $this->rule_config ?? [],
            'session_allowlist' => $this->session_allowlist ?? [],
            'status' => $this->status,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccounts(): HasMany
    {
        return $this->hasMany(BrokerAccount::class);
    }

    public function riskEvents(): HasMany
    {
        return $this->hasMany(RiskEvent::class);
    }

    public function riskDecisions(): HasMany
    {
        return $this->hasMany(RiskDecision::class);
    }

    public function riskLocks(): HasMany
    {
        return $this->hasMany(RiskLock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
