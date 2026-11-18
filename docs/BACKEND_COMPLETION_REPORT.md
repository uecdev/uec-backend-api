# UEC Backend & Administration Platform — Completion Report

**Date:** 2026-11-14 · **Version:** 1.0.0 (pre-release) · **Readers:** UEC project lead, product owner, engineering leads
**Companion documents:** [REQUIREMENTS_TRACEABILITY.md](REQUIREMENTS_TRACEABILITY.md) · [FRONTEND_API_INTEGRATION_HANDOFF.md](FRONTEND_API_INTEGRATION_HANDOFF.md) · [DEPLOYMENT.md](DEPLOYMENT.md)

## 1. Summary
The Laravel REST API and the custom TALL-stack administration dashboard are **built, tested and documented**.

- **API:** implements every endpoint in the mobile team's contract (`backend-handoff.md` §C.3: #1–#52 plus the three gateway routes).
- **Dashboard:** covers every area in the brief: executive analytics, members, content, finance, notifications, mobile-app configuration, reports and system administration.
- **Tests:** **103 automated tests (817 assertions) pass on PostgreSQL**. Separately, a live run through PostgreSQL, the Redis queue, a worker and the mail transport completed a full sign-up.

It is **not yet production-live**. What remains is outside the code:

1. Third-party credentials and provider approvals (§6).
2. The **store-billing policy decision** (handoff §E.1).
3. Servers, DNS and GitHub secrets for the deployment pipeline.
4. Real content and Bible text.
5. A contract run against the mobile app in live mode.

## 2. Verification performed
| Check | Result |
|---|---|
| PHPUnit feature + unit suite on PostgreSQL 18.6 (PHP 8.5, Laravel 13.42) | ✅ 103 / 103 passed, 817 assertions |
| Laravel Pint code style | ✅ clean |
| TypeScript strict type-check (`tsc --noEmit`) | ✅ 0 errors |
| Production asset build (Vite 8, Tailwind 4) | ✅ admin JS 97 KB gzip + Chart.js chunk 67 KB gzip (lazy-loaded), CSS 8 KB gzip |
| OpenAPI spec vs route list | ✅ 55 documented operations = 55 registered routes |
| Live smoke test (`php artisan serve`, real PostgreSQL + Redis) | ✅ `/up` healthy; error shapes; plans; config; admin login page with built assets; sign-up → Redis `otp` queue → worker → email OTP → verify → `/me`; activity tracking and delivery logs written |
| Visual QA of the dashboard in a browser | ⏳ Not performed (no browser available in this environment); all pages render without errors in tests |
| Contract run against the mobile app (`VITE_API_MODE=live`) | ⏳ Mobile repository not available here |
| Integrations against real vendors | ⏳ No credentials provided; every integration is tested against faithful HTTP fakes |

### Defects found by testing and fixed during development
- **Refresh-token theft detection was rolled back.** The revocation ran inside the transaction that then threw. It now commits before the 401 is returned.
- **Duplicate webhooks could abort a PostgreSQL transaction.** Replaced the caught unique violation with `INSERT … ON CONFLICT DO NOTHING`.
- **Sanctum consulted the session guard first by default.** It is now token-only for the stateless API.
- **The `/up` health route was silently dropped.** This happened when custom routing was used. It is now registered explicitly and checks the database and cache.
- **Redis returns numerics as strings,** which broke the health cache probe. Fixed.
- **Blade/Livewire compile issues.** `@switch` inside Livewire views and a directive glued to text both broke compilation; both fixed.
- **Model strict mode threw on freshly created models,** for example a new Google user checked for suspension in the same request. Relaxed to discard detection only.

## 3. Features & modules delivered
| Module | Status |
|---|---|
| Authentication: phone/email + OTP, login, Google, rotating refresh tokens, password reset | Completed and verified; WhatsApp/Google/ZeptoMail delivery awaiting credentials |
| Profile & account: name, avatar, contact change, in-app deletion | Completed and verified (Cloudinary awaiting credentials; local fallback works) |
| Content API: config, pages, hymnal, Daily Delight, Sunday School, Bible, events, branches, search | Completed and verified |
| Offline bundles with server-side entitlement enforcement | Completed and verified |
| Personal sync: favourites, programs, reading progress | Completed and verified |
| Subscriptions & payments: Paystack + Monnify, webhooks, renewals, plan change, cancel, grace, refunds | Implemented and verified against fakes; **awaiting gateway keys and the store-policy decision** |
| Notifications: email, WhatsApp, push, receipts, scheduled reminders, push campaigns | Implemented and verified against fakes; **awaiting credentials**; reminder schedule awaiting UEC confirmation |
| Feedback with attachments | Completed and verified |
| Admin dashboard (29 screens, 26 Livewire components) | Completed and verified by render, permission and workflow tests; visual QA pending |
| Reports (8) with CSV export | Completed and verified |
| Audit, integration and notification logs; health; jobs; webhook console | Completed and verified |
| CI/CD, deploy and rollback scripts, Nginx, Supervisor, cron | Implemented; awaiting servers and GitHub secrets |

## 4. API endpoints delivered
All 52 contract endpoints, the 3 gateway routes, `GET /api/v1/openapi.yaml` and `GET /up`. See [openapi.yaml](api/openapi.yaml) and the [frontend handoff](FRONTEND_API_INTEGRATION_HANDOFF.md#b-endpoint-reference). Behaviours that differ from or extend the proposal are listed in the handoff's §0.

## 5. Database
35 application tables (including admin `sessions`) plus Laravel/Spatie infrastructure tables, described with an ERD in [DATABASE.md](DATABASE.md):
- **Identity:** users, refresh_tokens, devices, user_activity_days, roles and permissions.
- **Content:** hymns ×5, devotional ×3, Sunday School ×3, Bible ×2, events, branches, pages.
- **Sync:** favourites, programs.
- **Billing:** plans, subscriptions, payments, webhook_events.
- **Platform:** app_settings, push_campaigns, notification_logs, audit_logs, integration_logs, feedback ×2.

All migrations are additive.

## 6. Pending external credentials, approvals & decisions
| Item | Owner | Blocks |
|---|---|---|
| **Store-billing model (Apple IAP / Google Play Billing vs in-app gateways)** — handoff §E.1 | UEC | Store submission of the paid tier |
| Paystack test + live secret keys; webhook URL registered; plans synced | UEC finance / backend | Payments |
| Monnify API key, secret, contract code; card tokenization enabled; webhook URL | UEC finance | Monnify payments |
| ZeptoMail token + verified sending domain (SPF/DKIM/DMARC) | UEC IT | Email OTPs, receipts, notices |
| WhatsApp Business: WABA, phone number ID, permanent token, **approved OTP template** | UEC | Phone sign-up |
| SMS fallback provider for phone OTP (J.1 #5) | UEC | Fallback only |
| Firebase project, service account, APNs key; config files to the mobile team | UEC / backend | Push |
| Google OAuth client IDs (web, iOS, Android + SHA fingerprints) | UEC / mobile | Google sign-in |
| Cloudinary account | UEC | Optimised avatars (fallback exists) |
| AWS S3 bucket + IAM user | UEC IT | Private media in production |
| Bible: KJV JSON/CSV file to import; licences for any other version | UEC | Bible reader |
| Grace period, free/premium split, prices, push schedule, future-dated devotionals | UEC product | Configuration values (defaults in place) |
| Privacy policy, terms, retention periods, DPO (NDPA) | UEC legal | Paywall links, compliance |
| Error monitoring tool (Sentry or Crashlytics) | UEC / engineering | Production observability |
| Domain, servers, DNS, TLS; GitHub environment secrets | UEC IT | Deployment |

## 7. Known limitations
- Pidgin and Igbo server messages and emails fall back to English until native-speaker translations are provided.
- App-store download and install counts are not shown; they need Play Console / App Store Connect analytics integrations. The dashboard labels them as unavailable and does not estimate them.
- The FCM delivery rate means "accepted by FCM"; open rates are not available from FCM.
- Universal-link files (`apple-app-site-association`, `assetlinks.json`) are not hosted yet; this needs the app-link domain.
- Laravel Horizon is not installed. The Operations page covers queue monitoring, and Horizon can be added on Linux production.
- A refund recorded at the gateway only arrives via webhook; dashboard "Mark refunded" covers refunds handled outside it.

## 8. Security & performance (summary)
- **Security:** see [SECURITY.md](SECURITY.md) for the OWASP API Top 10 mapping. Highlights: token rotation with theft detection, OTP pumping caps, server-side entitlements, HMAC webhooks with idempotency, amount-tamper protection, content-sniffed uploads, encrypted gateway authorizations, a redacted audit trail, granular admin RBAC, and a separate admin deployment.
- **Performance:** Redis-cached catalogue (ETag), offline bundles and Bible chapters; queued email, WhatsApp, push and webhooks; concurrent FCM fan-out; indexed queries with a trigram search index; activity tracking with a single write per user per day in a terminable middleware; lazy-loaded charts.

## 9. Frontend integration readiness
**Ready for staging integration.** The handoff gives base URLs, headers, the auth flows, TypeScript types for every payload, the error-code table, a mobile checklist and the OpenAPI file.

Four schema adjustments are expected on the app side; see handoff §0 items 1, 2/3, 4/5 and 13:
- ID format.
- Resend envelope.
- Locked audio/solfa.
- Nullable `share_url` and possibly empty `payment_providers`.

## 10. Next steps
1. UEC confirms the store-billing model and the [CONFIRM] values.
2. Provision staging (servers, DNS/TLS, PostgreSQL, Redis, S3) and GitHub secrets, then deploy with `deploy.yml`.
3. Install sandbox credentials and run every live test on Dashboard → Integrations.
4. Import KJV; editors load real content.
5. The mobile team runs `npm run dev:live` against staging and clears any contract mismatches.
6. Device QA, then the payments sandbox checklist, then production keys and the first `v1.0.0` tag.
