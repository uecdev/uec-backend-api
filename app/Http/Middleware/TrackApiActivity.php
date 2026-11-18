<?php

namespace App\Http\Middleware;

use App\Enums\Platform;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records one activity row per signed-in user per day (Africa/Lagos) from the
 * X-Platform / X-App-Version headers. Drives DAU/WAU/MAU, retention and the
 * platform/version distribution on the admin dashboard. One write per user per day.
 */
class TrackApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $user = $request->user('sanctum');
            if (! $user instanceof User || $response->getStatusCode() >= 500) {
                return;
            }

            $date = now(config('uec.timezone'))->toDateString();
            if (! Cache::add("activity:{$user->id}:{$date}", 1, now()->addHours(26))) {
                return;
            }

            $platform = Platform::fromHeader($request->header('X-Platform'))?->value;
            $version = $this->version($request->header('X-App-Version'));

            DB::table('user_activity_days')->upsert([[
                'user_id' => $user->id, 'date' => $date, 'platform' => $platform, 'app_version' => $version,
                'requests' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]], ['user_id', 'date'], ['platform', 'app_version', 'updated_at']);

            DB::table('users')->where('id', $user->id)->update(array_filter([
                'last_seen_at' => now(),
                'last_platform' => $platform,
                'last_app_version' => $version,
            ], fn ($v) => $v !== null));
        } catch (Throwable) {
            // Analytics must never affect API responses.
        }
    }

    private function version(?string $value): ?string
    {
        return $value && preg_match('/^\d+\.\d+(\.\d+)?$/', $value) ? $value : null;
    }
}
