<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next, string $profile = 'api'): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $h->set('X-Frame-Options', $profile === 'admin' ? 'SAMEORIGIN' : 'DENY');

        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($profile === 'api' && ! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
