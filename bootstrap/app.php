<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\ApiRequestContext;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackApiActivity;
use App\Support\Api\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| One codebase, two deployable surfaces (see docs/ARCHITECTURE.md):
|   UEC_SURFACE=api   -> https://api.<domain>  REST API + webhooks + payment callback
|   UEC_SURFACE=admin -> https://app.<domain>  Livewire administration dashboard
|   UEC_SURFACE=all   -> both (local development)
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        using: function () {
            $surface = config('uec.surface', 'all');

            // Health probe on every surface (the built-in `health:` option is ignored when `using` is set).
            Route::get('/up', HealthController::class)->name('health');
            if (in_array($surface, ['api', 'all'], true)) {
                Route::prefix('api/v1')
                    ->middleware('api')
                    ->name('api.v1.')
                    ->group(base_path('routes/api.php'));
            }

            if (in_array($surface, ['admin', 'all'], true)) {
                Route::middleware('web')->group(base_path('routes/admin.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'security.headers' => SecurityHeaders::class,
        ]);

        // API is stateless: no sessions, cookies or CSRF; bearer tokens only.
        $middleware->api(prepend: [ApiRequestContext::class], append: [
            TrackApiActivity::class,
            SecurityHeaders::class.':api',
        ]);
        $middleware->web(append: [SecurityHeaders::class.':admin']);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        // Load balancer / reverse proxy in front of both subdomains.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*') === '*' ? '*' : explode(',', (string) env('TRUSTED_PROXIES')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'refresh_token', 'reset_token', 'id_token']);

        $isApi = fn (Request $request) => $request->is('api/*');
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request) || $request->expectsJson());

        // Contract error shape: { message, code?, errors? } — never stack traces (backend-handoff C.1, I.3).
        $exceptions->render(function (ApiException $e) {
            return $e->render();
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->validator->errors()->first() ?: __('validation.invalid'),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? response()->json(['message' => __('api.unauthenticated'), 'code' => 'UNAUTHENTICATED'], 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? response()->json(['message' => __('api.forbidden'), 'code' => 'FORBIDDEN'], 403)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? response()->json(['message' => __('api.too_many_requests'), 'code' => 'RATE_LIMITED'], 429, $e->getHeaders())
                : null;
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? response()->json(['message' => __('api.not_found'), 'code' => 'NOT_FOUND'], 404)
                : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? response()->json(['message' => __('api.method_not_allowed'), 'code' => 'METHOD_NOT_ALLOWED'], 405)
                : null;
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || config('app.debug')) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'message' => $status === 503 ? __('api.service_unavailable') : ($e->getMessage() ?: __('api.server_error')),
                ], $status, $e->getHeaders());
            }

            return response()->json(['message' => __('api.server_error'), 'code' => 'SERVER_ERROR'], 500);
        });
    })->create();
