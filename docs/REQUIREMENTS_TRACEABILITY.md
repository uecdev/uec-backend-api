# Requirements Traceability Matrix

**Audience:** project lead, QA and reviewers. Each row links a documented requirement to its implementation, endpoint(s), tests and status.

Sources:
- **H** = `docs/backend-handoff.md`
- **B** = backend engineering brief (Laravel API + admin dashboard)
- **CR** = `docs/COMPLETION_REPORT.md` (mobile app)

**Status key:**
- ✅ Completed and verified (automated tests pass)
- 🟡 Implemented but awaiting external configuration
- 🟠 Partially implemented
- ⏳ Pending implementation
- ⛔ Blocked by external dependency or decision

## 1. Mobile API contract (H §C.3)

| Req | Endpoint(s) | Implementation | Tests | Status |
|---|---|---|---|---|
| H B.1 / D.2 Register with phone or email, OTP before account | #1 #2 #3 | `AuthController`, `AuthService`, `VerificationService`, `DeliverOtp` | `AuthRegistrationTest` | ✅ (phone OTP delivery 🟡 WhatsApp) |
| H B.1 OTP rules (6 digits, 10 min, 45 s, 5 attempts) | #2 #3 #9 #15 | `config/uec.php`, `VerificationService` | `AuthRegistrationTest` | ✅ |
| H D.3 Login, generic errors, throttle, `ACCOUNT_UNVERIFIED` | #4 | `AuthService::login` | `AuthLoginAndTokenTest` | ✅ |
| H D.4 Google sign-in, server verification, linking | #5 | `GoogleIdTokenVerifier`, `AuthService::loginWithGoogle` | `PasswordResetAndGoogleTest` | ✅ code · 🟡 client IDs |
| H D.1 / D.6 15-min access, rotating hashed refresh, family revocation, logout | #6 #7 | `TokenService` | `AuthLoginAndTokenTest` | ✅ |
| H D.5 Password reset, enumeration-safe, revoke sessions | #8 #9 #10 | `AuthService` | `PasswordResetAndGoogleTest` | ✅ |
| H D.7 Profile, avatar (Cloudinary, EXIF strip), contact change with OTP | #11–#15 | `MeController`, `MediaService`, `AccountService` | `ProfileAndFeedbackTest`, `IntegrationsTest` | ✅ (Cloudinary 🟡) |
| H B.2 Delete account (cancel subscription, revoke, anonymise) | #16 | `AccountService::delete` | `ProfileAndFeedbackTest` | ✅ |
| H H.1 Device registration, token pruning | #17 #18 | `DeviceController`, `FcmChannel`, `PushCampaignService` | `PersonalSyncTest`, `IntegrationsTest` | ✅ (FCM 🟡) |
| H #19 Remote config | #19 | `AppConfigController`, `AppSettings`, admin **Mobile app** | `ContentTest`, `AdminWorkflowsTest` | ✅ |
| H B.9 Pages | #20 | `PageController`, admin **Pages** | `ContentTest` | ✅ |
| H B.5 Hymnal catalogue (ETag), detail, solfa, audio | #21 #22 | `HymnController`, `ContentCache`, `HymnResources`, admin **Hymnal** | `ContentTest`, `AdminWorkflowsTest` | ✅ |
| H B.3 Devotional editions, entries, month filter, date | #23–#25 | `DevotionalController`, admin **Daily Delight** (+ CSV import) | `ContentTest`, `AdminWorkflowsTest` | ✅ |
| H B.3 Offline edition bundle (premium) | #26 | `ContentCache::devotionalBundle` | `ContentTest` | ✅ |
| H B.3 Reading progress | #27 #28 | `DevotionalController::progress/markRead` | `PersonalSyncTest` | ✅ |
| H B.4 Sunday School manuals, lessons, plan, search, bundle | #29–#33 | `SundaySchoolController`, admin **Sunday School** | `ContentTest` | ✅ |
| H B.6 Bible versions & chapters | #34 #35 | `BibleController`, `uec:bible:import`, admin **Bible** | `ContentTest`, `IntegrationsTest` | ✅ code · ⛔ KJV text file to import / licensing decision for other versions |
| H B.7 Events | #36 #37 | `DirectoryController`, admin **Events** | `ContentTest` | ✅ |
| H B.8 Branches (search, pagination, coordinates) | #38 | `DirectoryController`, admin **Branches** (+ CSV import) | `ContentTest` | ✅ |
| H #39 Global search | #39 | `DirectoryController::search` | `ContentTest` | ✅ |
| H C.5 Favourites & programs sync (idempotent, scoped) | #40–#44 | `SyncController` | `PersonalSyncTest` | ✅ |
| H E.2 Plans | #45 | `SubscriptionController::plans`, admin **Plans** | `ContentTest`, `AdminWorkflowsTest` | ✅ |
| H E.5 / E.6 Subscription state + entitlements, server enforcement | #46, #22, #26, #32 | `SubscriptionService` | `SubscriptionCheckoutTest`, `ContentTest` | ✅ |
| H E.3 Checkout (amount from DB, https callback, placeholder email) | #47 | `PaymentService::checkout`, gateways | `SubscriptionCheckoutTest` | ✅ code · 🟡 gateway keys |
| H E.3 Verify (gateway fallback, idempotent, ownership) | #48 | `PaymentService::verifyForUser/apply` | `SubscriptionCheckoutTest` | ✅ |
| H E.5 Plan change (next billing date, no proration), reactivation | #49 #50 | `SubscriptionService::previewChange/changePlan`, `SyncSubscriptionWithGateway` | `SubscriptionLifecycleTest` | ✅ |
| H E.5 Cancel at period end, gateway disable | #51 | `SubscriptionService::cancel` | `SubscriptionLifecycleTest` | ✅ |
| H B.12 Feedback (guests, 4 files, 5 MB, consent) | #52 | `FeedbackController`, admin **Feedback** | `ProfileAndFeedbackTest` | ✅ |
| H E.4 Paystack webhook (HMAC-SHA512, idempotent, queued) | W1 | `WebhookIngestor`, `PaystackWebhookHandler` | `PaymentWebhookTest` | ✅ code · 🟡 webhook URL registration |
| H E.4 Monnify webhook | W2 | `MonnifyWebhookHandler` | `PaymentWebhookTest` | ✅ code · 🟡 |
| H E.3 Payment callback → app scheme | W3 | `PaymentWebhookController::callback` | `SubscriptionCheckoutTest` | ✅ |
| H E.8 Failure scenarios (abandoned, delayed webhook, duplicate, tampering, renewal failure, refund) | — | `PaymentService`, lifecycle scheduler | Checkout/Webhook/Lifecycle tests | ✅ |
| H E.5 `past_due` grace period | — | `enforceLifecycle` (`UEC_SUBSCRIPTION_GRACE_DAYS`, default 3) | `SubscriptionLifecycleTest` | ✅ (grace length ⛔ **[CONFIRM]**) |
| H E.7 Store every payment, email receipts | — | `payments.raw` (sanitised), `PaymentReceiptNotification` | `SubscriptionCheckoutTest`, `IntegrationsTest` | ✅ (ZeptoMail 🟡) |
| H E.7 `GET /me/payments` history | — | — | — | ⏳ Not designed; proposed "later" in H |
| H E.1 Store-billing policy (IAP vs gateways) | — | Gateway abstraction ready for StoreKit/Play Billing | — | ⛔ UEC decision (blocking for store release) |

## 2. Conventions & cross-cutting (H §C, I)

| Req | Implementation | Tests | Status |
|---|---|---|---|
| `{data}` envelope, snake_case, error shape, codes | `Controller::data`, `ApiException`, `bootstrap/app.php` renderers | all API tests | ✅ |
| Dates `YYYY-MM-DD`, ISO-8601 UTC, kobo | `App\Support\Iso`, resources | `ContentTest` | ✅ |
| Rate limiting with `Retry-After` | named limiters + service-level OTP/login limits | auth tests | ✅ |
| `Accept-Language` for server messages | `ApiRequestContext`, `lang/en/*` | — | 🟠 English strings only; `pcm`/`ig` translations need native speakers (CR §6) |
| Cache headers / ETag | catalogue ETag, page/Bible `Cache-Control` | `ContentTest` | ✅ |
| CORS for Capacitor origins | `config/cors.php` | `ContentTest` | ✅ |
| HTTPS/HSTS, security headers | `SecurityHeaders`, Nginx configs | `ContentTest`, `AdminAccessTest` | ✅ |
| File type by content, re-encode images | `mimetypes` rules, `MediaService` | `ProfileAndFeedbackTest` | ✅ |
| Callback URL allow-list | `SubscriptionController::validatedCallback` | `SubscriptionCheckoutTest` | ✅ |
| Logs without secrets | `AuditLogger` redaction, `integration_logs` (no bodies), masked destinations | `IntegrationsTest` | ✅ |
| H H.2 Transactional emails (OTP, welcome, password/contact changed, subscription notices, receipts, account deleted) | `Notifications/*`, listeners | `IntegrationsTest`, auth tests | ✅ code · 🟡 ZeptoMail |
| H H.1 Scheduled pushes (daily devotional, SS reminder) | `ScheduledReminders` (off by default) | `IntegrationsTest` | ✅ code · ⛔ schedule **[CONFIRM]** |
| H H.3 / H.4 Notification inbox & preferences | — | — | ⏳ Not designed (H marks optional/confirm) |
| H I.4 NDPA: deletion, retention, privacy pages | deletion flow, `uec:prune`, Terms/Privacy pages in CMS | `ProfileAndFeedbackTest` | ✅ · ⛔ policy text & retention periods from UEC |
| H F Deep links: universal links files | — | — | ⏳ Needs app-link domain (J.1 #9) |
| H F Error monitoring (Sentry/Crashlytics) | — | — | ⛔ **[CONFIRM]** tool choice |

## 3. Administration dashboard (B §8)

| Req | Implementation | Tests | Status |
|---|---|---|---|
| A. Executive dashboard (users, DAU/WAU/MAU, registrations, platform split, retention, revenue, provider performance, subscriptions, notification delivery, content engagement, version distribution, date ranges, export) | `Livewire\Admin\Dashboard`, `DashboardMetrics`, Chart.js (TypeScript) | `AdminAccessTest` | ✅ |
| A. Store download/install metrics | Explicitly labelled "not available" (no store analytics integration) | — | ⛔ Requires Play Console / App Store Connect integration |
| B. User management (search/filter, profile, devices, activity, suspend/reactivate, revoke sessions) | `Users\Index`, `Users\Show` | `AdminAccessTest`, `AdminWorkflowsTest` | ✅ |
| C. Mobile app management (config, versions, min version, maintenance & update notices, version adoption, provider order) | `Engagement\MobileApp` | `AdminWorkflowsTest` | ✅ |
| D. Content management (hymns + audio/solfa, devotionals + CSV import, SS manuals/lessons/plan, events, branches + CSV import, pages, Bible) | `Livewire\Admin\Content\*` | `AdminWorkflowsTest` | ✅ |
| E. Financial management (payments, statuses, provider performance, failures, reconciliation/re-verify, refunds, reports, audit trail, plans + Paystack sync) | `Finance\Payments`, `Subscriptions`, `Plans`, `ReportBuilder` | `AdminWorkflowsTest` | ✅ |
| F. Notification management (create, target by platform/version/members, schedule, delivery stats, delivery log) | `Engagement\Notifications`, `PushCampaignService` | `AdminWorkflowsTest`, `IntegrationsTest` | ✅ code · 🟡 FCM |
| G. Reports & analytics (8 reports, date filters, CSV export, permission-gated) | `Engagement\Reports`, `ReportBuilder` | `AdminWorkflowsTest` | ✅ |
| H. System administration (roles & permission matrix, audit log, integration status/tests, background jobs, webhooks, health) | `System\*`, `IntegrationRegistry`, `HealthCheck` | `AdminAccessTest`, `AdminWorkflowsTest` | ✅ |
| No Filament; TALL + TypeScript | Livewire 4, Alpine (bundled), Tailwind v4, `resources/ts` (strict `tsc`) | CI typecheck | ✅ |
| Responsive, accessible, loading/empty/error states, confirmations | Blade components (modal, confirm dialog, toasts, empty states, aria labels) | page render tests | 🟠 Verified by render tests and HTTP smoke checks; visual/browser QA on devices not yet performed |

## 4. Platform, deployment & quality (B §9–16, deployment brief)

| Req | Implementation | Status |
|---|---|---|
| PostgreSQL schema, constraints, indexes, migrations, seeders, ERD | `database/migrations`, `DATABASE.md` | ✅ |
| Redis cache, queues, sessions | config + verified locally end-to-end | ✅ |
| Events, listeners, notifications, scheduler | `app/Events`, `app/Listeners`, `routes/console.php` | ✅ |
| Separate API (`api.`) and admin (`app.`) deployments, shared DB, no duplicated logic | `UEC_SURFACE`, `deploy/nginx/*` | ✅ |
| CI/CD: tests before deploy, independent surfaces, migrations, optimisation, assets, rollback, no secrets in git | `.github/workflows/ci.yml`, `deploy.yml`, `deploy/*.sh` | 🟡 Written; needs GitHub secrets + servers (not executed against real hosts) |
| OpenAPI documentation | `docs/api/openapi.yaml` (55 operations = 55 routes), Swagger UI | ✅ |
| Documentation set (architecture, DB, auth/security, integrations, env, deploy, queues, testing, ops, frontend handoff) | `docs/*.md` | ✅ |
| Testing: unit, feature, API, auth, roles, payments, webhooks, uploads, notifications | 103 tests / 817 assertions | ✅ |
| Contract verification against the mobile app's Zod schemas | — | ⛔ Mobile repository not available here; run `npm run dev:live` against staging |

## 5. Items intentionally not built (scope per designs, H §A.4)
Sermons, ministries & departments, event registrations, notification inbox/preferences, transaction-history screen, force-update screen (field reserved), anonymous (guest) push topics. Each needs designs and a UEC decision (H §J.1 #11–#12).
