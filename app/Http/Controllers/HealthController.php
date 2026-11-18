<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /up — liveness/readiness probe for load balancers and uptime monitors on
 * both subdomains. Returns 503 when the database or cache is unreachable.
 * Deliberately reveals no versions, hostnames or error details.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => false, 'cache' => false];

        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (Throwable) {
        }

        try {
            Cache::put('health:probe', 'ok', 10); // a string: Redis returns numerics as strings
            $checks['cache'] = Cache::get('health:probe') === 'ok';
        } catch (Throwable) {
        }

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
