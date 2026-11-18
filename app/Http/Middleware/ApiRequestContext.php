<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalises API requests: JSON responses, locale from Accept-Language (en|pcm|ig),
 * and the 1 MB JSON body limit (backend-handoff C.1, I.3).
 */
class ApiRequestContext
{
    private const MAX_JSON_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $locale = $this->locale((string) $request->header('Accept-Language', ''));
        app()->setLocale($locale);

        if ($request->isJson() && strlen($request->getContent()) > self::MAX_JSON_BYTES) {
            throw new ApiException(413, __('api.payload_too_large'), 'PAYLOAD_TOO_LARGE');
        }

        return $next($request);
    }

    private function locale(string $header): string
    {
        $supported = config('uec.locales');
        foreach (explode(',', strtolower($header)) as $part) {
            $code = trim(explode(';', $part)[0]);
            $code = explode('-', $code)[0];
            if (in_array($code, $supported, true)) {
                return $code;
            }
        }

        return 'en';
    }
}
