<?php

namespace App\Providers;

use App\Integrations\ZeptoMail\ZeptoMailTransport;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);
    }

    public function boot(): void
    {
        // Fail loudly outside production when attributes would be silently dropped on mass assignment.
        // (Missing-attribute checks are off: freshly created models legitimately lack unset columns.)
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // super_admin bypasses every permission check (granular checks still apply to other roles).
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        Mail::extend('zeptomail', fn () => new ZeptoMailTransport(
            (string) config('services.zeptomail.token'),
            (string) config('services.zeptomail.host'),
            config('services.zeptomail.bounce_address'),
        ));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $key = fn (Request $request) => $request->user('sanctum')?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
        // Login also has its own per-identifier lockout in AuthService.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(20)->by('auth|'.$r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(10)->by('otp|'.$r->ip()),
            Limit::perMinute(6)->by('otp-id|'.sha1((string) $r->input('verification_id'))),
        ]);
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(10)->by('up|'.$key($r)));
        RateLimiter::for('payments', fn (Request $r) => Limit::perMinute(10)->by('pay|'.$key($r)));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by('wh|'.$r->ip()));
        RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(5)->by('admin|'.$r->ip().'|'.mb_strtolower((string) $r->input('email'))));
    }
}
