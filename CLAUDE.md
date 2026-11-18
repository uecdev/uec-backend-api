# UEC Backend — Agent Guidance

Laravel 13 (PHP 8.4+) API + Livewire 4 admin dashboard for the United Evangelical Church mobile apps. Read `docs/ARCHITECTURE.md` before structural changes.

## Ground rules
- The API contract is `docs/backend-handoff.md`. The mobile app validates every response with Zod: never change a response shape without updating `docs/api/openapi.yaml` and `docs/FRONTEND_API_INTEGRATION_HANDOFF.md` §0.
- Success bodies use `{ "data": … }` via `Controller::data()`. Errors are thrown as `App\Support\Api\ApiException` (or validation exceptions) and rendered in `bootstrap/app.php`. Keys are snake_case, dates `Y-m-d`, timestamps via `App\Support\Iso::ts()`, money in kobo.
- One codebase, two surfaces: `UEC_SURFACE` (`api` | `admin` | `all`). API routes go in `routes/api.php`, dashboard routes in `routes/admin.php`.
- Business logic lives in `app/Services`. Vendor HTTP calls live in `app/Integrations` or the payment gateways and use the `LogsIntegrationCalls` trait. Secrets come only from `config/services.php` (env).
- Every payment state change goes through `PaymentService::apply()` (locked, idempotent).
- Do not use Filament or other admin generators. Admin pages are Livewire components using the `AdminComponent` (+ `WithDataTable`) traits, permission-checked with `authorizeAbility()` in `mount()` and in every action, and audited with `audit()`.
- Do not use `@switch` in Livewire Blade views (morph markers break it), and keep Blade directives separated from preceding word characters.
- Frontend scripting is TypeScript in `resources/ts`; Tailwind tokens are in `resources/css/admin.css`.

## Commands
```bash
php artisan test                    # PostgreSQL test DB (uec_api_test); Http::preventStrayRequests is on
vendor/bin/pint                     # format
npm run typecheck && npm run build  # admin assets
php artisan uec:admin:create        # first administrator
php artisan uec:bible:import KJV file.json
```

## Testing conventions
- Extend `Tests\TestCase` (seeds roles/plans/categories; helpers `member()`, `tokenFor()`, `capturedOtp()`, `configurePaystack()`, `rsaKeyPair()`).
- Fake every external API with `Http::fake`. Add a feature test for every endpoint or admin action you add.
