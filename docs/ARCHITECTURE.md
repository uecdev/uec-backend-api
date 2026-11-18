# UEC Backend — System Architecture

**Audience:** engineers who will run, extend or review this codebase.
**Related:** [DATABASE.md](DATABASE.md) · [SECURITY.md](SECURITY.md) · [INTEGRATIONS.md](INTEGRATIONS.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [api/openapi.yaml](api/openapi.yaml)

---

## 1. What this system is

One Laravel 13 application (PHP 8.4+, PostgreSQL, Redis) that powers two products:

| Surface | Domain | Who uses it | Technology |
|---|---|---|---|
| **REST API** | `https://api.<domain>/api/v1` | UEC Android/iOS apps (Ionic Vue + Capacitor), payment gateways (webhooks) | Laravel controllers, Sanctum bearer tokens |
| **Admin dashboard** | `https://app.<domain>` | UEC content editors, finance and administrators | Livewire 4 + Alpine.js + Tailwind CSS v4 + TypeScript (no Filament) |

The same codebase is deployed twice. The `UEC_SURFACE` environment variable (`api`, `admin` or `all`) decides which routes each deployment registers (`bootstrap/app.php`). Models, services, validation and integrations are shared, so business logic exists once.

```
                    ┌──────────────── Android / iOS app ────────────────┐
                    │  Bearer token · snake_case JSON · Zod-validated   │
                    └───────────────────────┬───────────────────────────┘
                                            │ HTTPS
┌──────────── api.<domain> (UEC_SURFACE=api) ▼ ───────────┐   ┌──── app.<domain> (UEC_SURFACE=admin) ────┐
│ routes/api.php → Api\V1 controllers → Form Requests      │   │ routes/admin.php → Livewire components   │
│        │                                                 │   │   (session + CSRF, role permissions)     │
│        ▼                                                 │   │        │                                 │
│  ┌──────────────── shared application core ─────────────────────────────────────────┐              │
│  │ Services: Auth · Verification(OTP) · Account · Subscriptions · Payments ·        │              │
│  │           Content cache/bundles · Push campaigns · Analytics · Audit · Settings   │              │
│  │ Integrations: Paystack · Monnify · ZeptoMail · WhatsApp · FCM · Google · Cloudinary│             │
│  │ Models (Eloquent) · Events/Listeners · Notifications · Jobs                       │              │
│  └───────────────┬───────────────────────────┬──────────────────────┬──────────────┘              │
└──────────────────┼───────────────────────────┼──────────────────────┼─────────────────────────────┘
                   ▼                           ▼                      ▼
             PostgreSQL 15+              Redis 7 (cache,         S3 (private media)
             (single shared DB)          queues, sessions,       Cloudinary (images)
                                         OTP challenges)
                   ▲
     queue workers (Supervisor) + scheduler (cron) run on the API host
```

## 2. Code layout

```
app/
  Console/Commands/        uec:admin:create · uec:bible:import · uec:prune
  Enums/                   SubscriptionStatus · PaymentStatus · Platform · VerificationPurpose · PushCampaignStatus
  Events/ Listeners/       domain events → notifications, cache rebuilds, delivery logs
  Http/
    Controllers/Api/V1/    one controller per contract area (Auth, Me, Devotional, …)
    Controllers/Admin/     private file streaming for the dashboard
    Middleware/            ApiRequestContext · TrackApiActivity · SecurityHeaders · EnsureAdmin
    Requests/Api/V1/       Form Requests (normalise phone/email before validating)
    Resources/V1/          payload builders that mirror the contract exactly
  Integrations/            one thin client per vendor + LogsIntegrationCalls (timeouts, retries, logs)
  Jobs/                    DeliverOtp · ProcessWebhookEvent · SyncSubscriptionWithGateway · SendPushCampaign
  Livewire/Admin/          dashboard pages (Users, Content, Finance, Engagement, System)
  Models/                  Eloquent models (ULID keys)
  Notifications/           transactional notices + Mail/WhatsApp/FCM channels
  Services/                business logic (no HTTP concerns)
  Support/                 ApiException, Phone/Email helpers, USFM books, RichText, Permissions
config/uec.php             business rules (OTP, tokens, entitlements, content rules) — [CONFIRM] items are here
config/services.php        third-party credentials (env only)
routes/api.php             /api/v1 (mobile contract)      routes/admin.php   dashboard
routes/console.php         scheduler
resources/ts/              TypeScript: Livewire/Alpine bootstrap, charts (code-split)
resources/css/admin.css    Tailwind v4 design tokens (UEC green/cream/gold)
deploy/                    nginx vhosts, Supervisor, cron, deploy.sh, rollback.sh
.github/workflows/         CI (tests + typecheck + build) and Deploy (per surface, staging/production)
```

## 3. Module map

| Module | Main classes | Contract (#) |
|---|---|---|
| Authentication & identity | `AuthService`, `TokenService`, `VerificationService`, `GoogleIdTokenVerifier` | #1–#10 |
| Profile & account | `MeController`, `AccountService`, `MediaService` | #11–#16 |
| Devices & push | `DeviceController`, `FcmClient`, `PushCampaignService`, `ScheduledReminders` | #17–#18, H.1 |
| Remote config | `AppSettings`, `AppConfigController` | #19 |
| Content | `ContentCache`, Hymn/Devotional/SundaySchool/Bible/Directory controllers | #20–#39 |
| Personal sync | `SyncController`, `DevotionalController@progress` | #27–#28, #40–#44 |
| Subscriptions & payments | `SubscriptionService`, `PaymentService`, `PaymentGatewayManager`, `PaystackGateway`, `MonnifyGateway`, webhook handlers | #45–#51, W1–W3 |
| Feedback | `FeedbackController` | #52 |
| Notifications | `UecNotification` subclasses, `WhatsAppChannel`, `FcmChannel`, `ZeptoMailTransport`, `LogNotificationDelivery` | H.2 |
| Analytics & reports | `DashboardMetrics`, `ReportBuilder` | admin |
| Audit & operations | `AuditLogger`, `IntegrationRegistry`, `HealthCheck` | admin |

Modules from the original brief that the designs do not include (sermons, ministries, event registrations, notification inbox, notification preferences, transaction-history screen) were **not** built. See the handoff's §A.4 note and [REQUIREMENTS_TRACEABILITY.md](REQUIREMENTS_TRACEABILITY.md).

## 4. Key flows

### 4.1 Request pipeline (API)
`HandleCors` → `ApiRequestContext` (forces JSON, sets the locale from `Accept-Language`, enforces the 1 MB JSON limit) → `throttle:*` → `auth:sanctum` where required → controller → Form Request → service → resource builder → `{ "data": … }`. On the way out, `TrackApiActivity` (terminable) records one activity row per signed-in user per day, and `SecurityHeaders` adds `nosniff`, `X-Frame-Options`, CSP and HSTS.

All errors go through `bootstrap/app.php` and come out in the contract shape `{ message, code?, errors? }`. Stack traces are never returned.

### 4.2 Sign-up with OTP
`POST /auth/register` validates the input and stores an encrypted draft plus an HMAC-hashed code in Redis (`verification:{id}`, 10-minute validity). It then queues `DeliverOtp` on the high-priority `otp` queue: WhatsApp template for phones, ZeptoMail for email. `POST /auth/register/verify` checks the code in constant time (5 attempts, then `OTP_LOCKED`), re-checks uniqueness, creates the user, assigns `member`, issues tokens and fires `AccountEvent('registered')`, which sends a queued welcome email.

### 4.3 Tokens
- **Access token:** a Sanctum personal access token that expires after 15 minutes.
- **Refresh token:** opaque, 60 days, stored as SHA-256 and linked to its access token. Every refresh rotates both tokens within a **family**. Presenting a rotated refresh token again revokes the whole family and is logged as `security.refresh_token_reuse`.
- **Revocation:** happens in the same database transaction, and the 401 is thrown only after commit.

### 4.4 Payments (Option C: in-app Paystack/Monnify, as built by the mobile team)

```
App ─POST /me/subscription/checkout──▶ PaymentService::checkout
                                       ├ payments(pending, amount from DB plan, reference uec_pay_…)
                                       └ Gateway::initialize ──▶ Paystack/Monnify hosted page
App opens authorization_url ─────────────────────────────────▶ user pays
Gateway ─POST /webhooks/{provider} (HMAC)──▶ WebhookIngestor: verify → INSERT … ON CONFLICT DO NOTHING → queue
                                       └ ProcessWebhookEvent → handler → PaymentService::apply()
Browser ─GET /payments/callback?reference──▶ 302 uecapp://payment/callback?reference=…
App ─POST /me/subscription/verify (polls)──▶ PaymentService::verifyForUser → Gateway::verify if still pending → apply()
```

`PaymentService::apply()` is the **only** place a gateway result changes state. It row-locks the payment, is idempotent, checks amount, currency and reference (a mismatch is logged as a security event and the payment stays pending), then activates or renews the subscription and dispatches `PaymentSucceeded` (receipt email).

**Renewals:**
- **Paystack with a plan code:** Paystack bills on its schedule and reports through `charge.success`; `recordGatewayRenewal` handles it.
- **Monnify, or Paystack without a plan code:** the scheduler (`uec:subscriptions:renew`, every 15 minutes) charges the stored reusable card authorization.
- **Plan changes:** take effect on the next billing date. For Paystack the old gateway subscription is disabled and a new one is created with `start_date = current_period_end`.

**Lifecycle (`uec:subscriptions:lifecycle`, hourly):**
- `cancelled` → `expired` at `access_until`.
- `active` with no renewal 24 hours after period end → `past_due`.
- `past_due` → `expired` after the grace period (default 3 days).
- A refund or chargeback expires the subscription immediately.

Entitlements (`hymn.audio`, `hymn.solfa`, `offline.downloads`, `archive.editions`) are computed server-side from the live subscription and enforced on the download and media endpoints.

> ⚠️ The handoff's §E.1 store-policy decision (Apple IAP / Google Play Billing versus in-app gateways) is still open. Gateways sit behind `PaymentGateway`. Adding a `StoreKitGateway` or `PlayBillingGateway` that validates receipts would reuse `SubscriptionService` and the entitlement logic unchanged.

### 4.5 Notifications
Domain events (`AccountEvent`, `PaymentSucceeded`, `SubscriptionChanged`) → listeners → queued `UecNotification` subclasses (`notifications` queue, `afterCommit`). Each notice picks its channels:

- **mail** (ZeptoMail HTTP API transport) when an email address exists.
- **WhatsApp** only when an approved template is configured for that notice type.
- **FCM** for subscription events, to every registered device; invalid tokens are pruned.

Every delivery attempt is logged to `notification_logs`, with the destination masked.

Push campaigns (admin or system reminders) fan out per device token in batches of 500, with 20 concurrent HTTP/2 requests. This allows segmentation by platform, app version or member, and gives real delivery counts.

### 4.6 Content delivery
- **Hymn catalogue:** one cached payload with an ETag (`304` on `If-None-Match`).
- **Offline bundles (devotional editions, SS manuals):** pre-rendered into Redis by `ContentCache` and rebuilt by the queued `RefreshContentCaches` listener whenever an editor saves content (`ContentPublished` event). Nginx gzips the JSON.
- **Bible chapters:** cached for 7 days behind a revision counter that the importer bumps.

## 5. Background processing

| Process | Where | Command |
|---|---|---|
| Queue workers | API host, Supervisor | `queue:work redis --queue=otp,webhooks,notifications,default` (+1 dedicated `notifications` worker for long push campaigns) |
| Scheduler | API host, cron every minute | `schedule:run`; tasks use `onOneServer()` |
| Scheduled tasks | — | heartbeat (1 min), `uec:push:dispatch` (1 min), `uec:subscriptions:renew` (15 min), `uec:subscriptions:lifecycle` (hourly), `uec:prune` (02:30 Lagos), failed-job pruning |

Laravel Horizon was evaluated and **not** adopted. It requires `ext-pcntl`, which blocks Windows development machines. The Operations page in the dashboard provides queue backlog, failed-job retry and deletion, a webhook reprocessing console and a scheduler heartbeat. Horizon can be added on Linux production later without code changes.

## 6. Technical decisions (summary)

| Decision | Rationale |
|---|---|
| One codebase, two surfaces (`UEC_SURFACE`) | Meets the separate-subdomain requirement without duplicating models, rules or integrations. Each deployment only exposes its own routes. |
| ULID primary keys | Contract IDs are opaque strings; ULIDs sort by time and don't leak counts. |
| Sanctum + custom rotating refresh tokens | Sanctum has no refresh tokens; the contract requires 15-minute access tokens plus rotated, hashed, family-revocable refresh tokens. Sanctum is configured token-only (`guard => []`). |
| OTP challenges in Redis, not SQL | Required by the contract; short-lived, encrypted drafts, no orphaned user rows. |
| Thin HTTP clients instead of vendor SDKs | Paystack, Monnify, ZeptoMail, WhatsApp, FCM v1 and Cloudinary are simple REST APIs. Avoiding SDKs keeps the dependency surface small and gives uniform timeouts, retries and `integration_logs`. `firebase/php-jwt` covers both Google ID-token verification and FCM service-account signing. |
| `INSERT … ON CONFLICT DO NOTHING` for webhook de-duplication | Idempotent, and never aborts an enclosing PostgreSQL transaction (unlike catching a unique violation). |
| Livewire + Alpine + Tailwind + TypeScript (no Filament) | Required by the brief. Chart.js is code-split so pages without charts don't download it. |
| Fonts self-hosted (`@fontsource/lato`) | No third-party font requests from an internal admin tool. |
| Predis client | Works everywhere without a PHP extension; switch `REDIS_CLIENT=phpredis` on servers that have it. |

## 7. Extending the system

- **New API endpoint:** add the route in `routes/api.php`, a controller method, a Form Request if the input is non-trivial, a payload builder in `Http/Resources/V1`, a feature test, and the OpenAPI entry. CI does not diff routes against the spec yet; the snippet in [TESTING.md](TESTING.md) does.
- **New admin page:** a Livewire component in `app/Livewire/Admin/...` using the `AdminComponent` (and `WithDataTable`) traits, a view in `resources/views/livewire/admin/...`, a route in `routes/admin.php`, a nav entry in `layouts/admin.blade.php`, and a permission in `App\Support\Permissions`.
- **New payment provider:** implement `PaymentGateway`, register it in `PaymentGatewayManager`, and add a webhook handler.
