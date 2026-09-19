<?php

namespace App\Hardening\Security;

use App\Hardening\Support\HardeningSafety;

/**
 * Replay/TLS/Headers/CORS/CSRF/XSS/SQLi/IDOR/SSRF defense posture.
 * Complements existing Sanctum CSRF + permission middleware.
 */
class SecurityDefenseService
{
    /** @return array<string, string> */
    public function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];
    }

    /** @return array<string, mixed> */
    public function corsPolicy(): array
    {
        return [
            'mode' => 'SAME_ORIGIN_PREFERRED',
            'credentials' => true,
            'wildcard_origin' => false,
            'note' => 'Stateful Sanctum session — configure SANCTUM_STATEFUL_DOMAINS explicitly in deploy',
        ];
    }

    /** @return array<string, mixed> */
    public function posture(): array
    {
        return [
            'phase' => HardeningSafety::PHASE,
            'csrf' => 'SANCTUM_XSRF_REQUIRED_ON_WRITES',
            'xss' => 'CSP_PLUS_JSON_APIS_NO_INLINE_HTML_REFLECT',
            'sqli' => 'ELOQUENT_PARAMETERIZED_ONLY',
            'idor' => 'OWNERSHIP_CHECKS_PLUS_PERMISSION_MIDDLEWARE',
            'ssrf' => 'NO_USER_CONTROLLED_URL_FETCH_IN_HARDENING',
            'tls' => 'REQUIRED_IN_DEPLOY',
            'replay' => 'NONCE_TIMESTAMP_FOUNDATION',
            'headers' => $this->securityHeaders(),
            'cors' => $this->corsPolicy(),
            'live_auto_exists' => false,
            'order_send_phase19' => 0,
        ];
    }

    public function assertNoUserControlledUrl(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }
        // Hard block SSRF-shaped user URLs in Phase 19 hardening surfaces
        throw new \InvalidArgumentException('User-controlled URL fetch is forbidden (SSRF defense).');
    }

    /** @return array<string, mixed> */
    public function idorProbeResult(bool $owned): array
    {
        return [
            'owned' => $owned,
            'cross_user' => $owned ? 'ALLOWED' : 'DENIED_404',
            'policy' => 'Fail closed — never leak existence across tenants',
        ];
    }
}
