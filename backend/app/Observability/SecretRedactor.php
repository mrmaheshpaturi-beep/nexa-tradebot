<?php

namespace App\Observability;

/**
 * Redacts secrets from log/context payloads.
 */
class SecretRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'api_key', 'apikey', 'secret',
        'authorization', 'mt5_password', 'investor_password', 'service_token',
        'access_token', 'refresh_token', 'private_key', 'client_secret',
        'broker_password', 'login_password', 'webhook_secret',
    ];

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->isSensitiveKey($k)) {
                    $out[$k] = '[REDACTED]';
                } else {
                    $out[$k] = $this->redact($v);
                }
            }

            return $out;
        }

        if (is_string($value)) {
            return preg_replace('/(Bearer\s+)\S+/i', '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
