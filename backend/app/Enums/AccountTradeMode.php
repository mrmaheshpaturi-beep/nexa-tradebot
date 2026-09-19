<?php

namespace App\Enums;

enum AccountTradeMode: string
{
    case Demo = 'DEMO';
    case Live = 'LIVE';
    case Contest = 'CONTEST';
    case Unknown = 'UNKNOWN';

    public static function fromBridge(?string $value): self
    {
        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, ['DEMO', 'ACCOUNT_TRADE_MODE_DEMO', '0'], true) || $normalized === '0') {
            return self::Demo;
        }
        if (in_array($normalized, ['LIVE', 'REAL', 'ACCOUNT_TRADE_MODE_REAL', '2'], true) || $normalized === '2') {
            return self::Live;
        }
        if (in_array($normalized, ['CONTEST', 'ACCOUNT_TRADE_MODE_CONTEST', '1'], true) || $normalized === '1') {
            return self::Contest;
        }

        return self::Unknown;
    }

    public function allowsDemoExecution(): bool
    {
        return $this === self::Demo;
    }
}
