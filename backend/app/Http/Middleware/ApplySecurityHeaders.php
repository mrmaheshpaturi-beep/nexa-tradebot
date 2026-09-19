<?php

namespace App\Http\Middleware;

use App\Hardening\Security\SecurityDefenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply Phase 19 security headers to API responses.
 */
class ApplySecurityHeaders
{
    public function __construct(private readonly SecurityDefenseService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        foreach ($this->security->securityHeaders() as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }
}
