<?php

namespace App\Execution;

use App\Contracts\DemoBridgeClient;
use App\Enums\AccountTradeMode;
use App\Enums\TradingEnvironment;
use App\Models\BrokerAccount;
use Illuminate\Validation\ValidationException;

/**
 * Multi-layer DEMO account verification. LIVE and UNKNOWN hard-fail.
 */
class DemoAccountVerifier
{
    public function __construct(private readonly DemoBridgeClient $bridge) {}

    /**
     * @return array{trade_mode:string,login:string,server:string,verified_at:string,source:string,raw:array}
     */
    public function verify(BrokerAccount $account, bool $refreshFromBridge = true): array
    {
        if ($account->environment === TradingEnvironment::Live) {
            throw ValidationException::withMessages([
                'account' => 'LIVE accounts are hard-rejected by DEMO verification.',
            ]);
        }
        if ($account->environment !== TradingEnvironment::Demo) {
            throw ValidationException::withMessages([
                'account' => 'Only DEMO broker accounts can be verified for DEMO execution.',
            ]);
        }

        $snapshot = $refreshFromBridge
            ? $this->bridge->verifyAccount($account)
            : ($account->demo_verification ?? []);

        if ($snapshot === []) {
            throw ValidationException::withMessages([
                'account' => 'DEMO verification requires a fresh bridge account snapshot.',
            ]);
        }

        $tradeMode = AccountTradeMode::fromBridge(
            (string) ($snapshot['trade_mode'] ?? $snapshot['tradeMode'] ?? 'UNKNOWN')
        );

        if ($tradeMode === AccountTradeMode::Live) {
            throw ValidationException::withMessages([
                'account' => 'LIVE trade mode hard-fail at DEMO verification layer.',
            ]);
        }
        if ($tradeMode === AccountTradeMode::Unknown || $tradeMode === AccountTradeMode::Contest) {
            throw ValidationException::withMessages([
                'account' => 'UNKNOWN/unsupported account trade mode hard-fails DEMO verification.',
            ]);
        }
        if (! $tradeMode->allowsDemoExecution()) {
            throw ValidationException::withMessages([
                'account' => 'Account trade mode is not DEMO.',
            ]);
        }

        $login = (string) ($snapshot['account_id'] ?? $snapshot['login'] ?? '');
        $server = (string) ($snapshot['server'] ?? $account->broker_server ?? '');
        if ($login === '' || $server === '') {
            throw ValidationException::withMessages([
                'account' => 'DEMO verification requires broker login and server.',
            ]);
        }

        if ($account->broker_login && (string) $account->broker_login !== $login) {
            throw ValidationException::withMessages([
                'account' => 'Bridge login does not match the mapped DEMO account login.',
            ]);
        }
        if ($account->broker_server && strcasecmp((string) $account->broker_server, $server) !== 0) {
            throw ValidationException::withMessages([
                'account' => 'Bridge server does not match the mapped DEMO account server.',
            ]);
        }

        $verified = [
            'trade_mode' => AccountTradeMode::Demo->value,
            'login' => $login,
            'server' => $server,
            'verified_at' => now()->utc()->toIso8601String(),
            'source' => (string) ($snapshot['source'] ?? 'BRIDGE'),
            'raw' => [
                'trade_mode' => $snapshot['trade_mode'] ?? null,
                'currency' => $snapshot['currency'] ?? null,
                'leverage' => $snapshot['leverage'] ?? null,
            ],
        ];

        $account->forceFill([
            'broker_login' => $login,
            'broker_server' => $server,
            'verified_trade_mode' => AccountTradeMode::Demo->value,
            'demo_verified_at' => now(),
            'demo_verification' => $verified,
        ])->save();

        return $verified;
    }

    public function assertStillVerified(BrokerAccount $account): void
    {
        if ($account->verified_trade_mode !== AccountTradeMode::Demo->value
            || $account->demo_verified_at === null
            || $account->demo_verified_at->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages([
                'account' => 'DEMO verification is missing or stale; refresh required before submit.',
            ]);
        }
    }
}
