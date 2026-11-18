# UEC Backend — Testing

**Audience:** engineers changing the code and QA running release checks.

## 1. Running the suite
```bash
# PostgreSQL test database (tests run on PostgreSQL, not SQLite: jsonb, ILIKE, partial indexes and time-zone SQL are exercised)
createdb uec_api_test
php artisan test                 # or: php artisan test --parallel
vendor/bin/pint --test           # code style
npm run typecheck && npm run build
```
`phpunit.xml` targets the `uec_api_test` database (credentials from `.env`), uses array cache/mail and the sync queue, and disables the Have I Been Pwned lookup. `Http::preventStrayRequests()` is on in every test: **no test can reach a real third-party API**, so every integration is exercised against an explicit fake.

## 2. Current results
**103 tests, 817 assertions, all passing** (PostgreSQL 18.6, PHP 8.5, Laravel 13.42), plus TypeScript strict type-check and production Vite build.

| Suite | File | Covers |
|---|---|---|
| Auth: registration | `tests/Feature/Api/AuthRegistrationTest.php` | Email/phone sign-up only after OTP, normalisation and masking, validation, wrong code → 422 then `OTP_LOCKED`, expiry → 410, resend cooldown and code invalidation |
| Auth: login & tokens | `tests/Feature/Api/AuthLoginAndTokenTest.php` | Phone/email login, generic errors, throttling, `ACCOUNT_UNVERIFIED` challenge flow, suspension, refresh rotation, **reuse → family revoked**, 15-min expiry, 60-day refresh expiry, logout |
| Password reset & Google | `tests/Feature/Api/PasswordResetAndGoogleTest.php` | Enumeration-safe forgot, full reset revoking sessions, single-use reset token; Google ID token signature/aud/expiry/email_verified, linking by email then sub, new account creation |
| Content | `tests/Feature/Api/ContentTest.php` | Remote config provider filtering, pages, hymn catalogue + ETag/304, **audio/solfa entitlement enforcement**, devotional visibility rules, offline downloads `PREMIUM_REQUIRED`, archive gating, SS manual/plan/lesson/search, Bible chapters + 404s, events, branch pagination/search, global search, plans in kobo, CORS allow-list, security headers |
| Personal sync | `tests/Feature/Api/PersonalSyncTest.php` | Favourites full replacement (drops unknown ids), programs 201/204 + last-write-wins + **cross-user isolation**, validation, idempotent reading progress, device upsert/move/delete |
| Profile & feedback | `tests/Feature/Api/ProfileAndFeedbackTest.php` | Profile update (HTML stripped), avatar re-encode to 512² JPEG, malicious upload rejected, contact change with OTP + uniqueness, **account deletion** (gateway cancel, anonymise, tokens revoked), guest feedback with attachments, file count/type/size limits |
| Checkout & verify | `tests/Feature/Api/SubscriptionCheckoutTest.php` | Paystack checkout uses DB amount + https callback + placeholder email, open-redirect protection, `ALREADY_SUBSCRIBED`, gateway outage → 502, verify activates when webhook delayed (idempotent), **amount tampering kept pending + security log**, abandoned, other users' references → 404, Monnify checkout + verify, callback redirect safety |
| Webhooks | `tests/Feature/Api/PaymentWebhookTest.php` | HMAC verification, duplicate de-dup, tampered amount, `subscription.create`, Paystack-initiated renewal, `invoice.payment_failed` → past_due, `not_renew` → cancelled, refund → expired, Monnify signed webhook |
| Lifecycle | `tests/Feature/Api/SubscriptionLifecycleTest.php` | Change preview + `SAME_PLAN`, scheduled change without charge, cancel keeps access then expires, reactivation, cancel survives gateway outage, **Monnify card-token renewal applying the pending plan**, one attempt per period, failed renewal → grace → expired, overdue → past_due, Paystack plan change re-creates the gateway subscription |
| Integrations | `tests/Feature/IntegrationsTest.php` | WhatsApp OTP template payload, email OTP, ZeptoMail transport payload, Cloudinary signature, push campaign segmentation + delivery counts + token pruning, route allow-list, daily reminder once/day, notification channel routing + delivery logs, FCM result classification, Bible import, admin CLI password policy |
| Admin access | `tests/Feature/Admin/AdminAccessTest.php` | Every dashboard page renders, guests/members refused, **per-role permission boundaries**, nav filtering, admin login + rate limit + audit, suspended admins, security headers |
| Admin workflows | `tests/Feature/Admin/AdminWorkflowsTest.php` | Hymn editor (stanzas, categories, solfa, duplicate numbers), audio upload/delete on private storage, devotional editor + CSV import (atomic validation), suspend/reactivate, remote config validation, push scheduling/cancel + route validation, payment re-verify/refund + subscription cancel, plan price change + Paystack sync, admin creation with one-time password + last-super-admin protection, all reports render + CSV export |
| Unit | `tests/Unit/SupportTest.php` | Phone normalisation, masking, USFM validation, kobo formatting |

## 3. Writing tests
- Extend `Tests\TestCase`: it seeds roles, plans and categories and offers `member()`, `tokenFor()`, `capturedOtp()`, `configurePaystack()`, `configureMonnify()`, `paystackSignature()` and `rsaKeyPair()`.
- Fake every external call with `Http::fake([...])`. Assert payloads with `Http::assertSent`.
- API requests are stateless: `TestCase::call()` forgets the Sanctum user between `/api/*` requests, so each request authenticates from its own bearer token.

## 4. Contract check against the OpenAPI spec
```bash
php artisan route:list --path=api --json | python3 - <<'PY'
import json, re, sys, yaml
routes = json.load(sys.stdin); spec = yaml.safe_load(open('docs/api/openapi.yaml'))
norm = lambda m, u: (m, re.sub(r'\{[^}]+\}', '{}', u))
impl = {norm(m, '/' + r['uri']) for r in routes for m in r['method'].split('|') if m != 'HEAD'}
doc = {norm(m.upper(), '/api/v1' + p) for p, v in spec['paths'].items() for m in v}
print('undocumented:', sorted(impl - doc)); print('unimplemented:', sorted(doc - impl))
PY
```
Both lists are empty for this release.

To verify against the mobile app's executable contract (its Zod schemas), run the app with `VITE_API_MODE=live VITE_API_BASE_URL=https://api-staging.<domain>/api/v1` (`npm run dev:live` in `uec-mobile-app`) and watch the console for `API contract mismatch`. **This has not been done yet**: the mobile repository was not available in this environment.

## 5. Post-deploy smoke checks
```bash
curl -fsS https://api.<domain>/up                      # {"status":"ok",...}
curl -fsS https://api.<domain>/api/v1/app/config       # payment_providers lists configured gateways
curl -fsS https://api.<domain>/api/v1/subscription/plans
curl -s  https://api.<domain>/api/v1/me                # 401 {"code":"UNAUTHENTICATED"}
curl -fsS https://app.<domain>/up && curl -sI https://app.<domain>/login | head -1
```
Then on the dashboard: Operations (all green, heartbeat < 3 min), Integrations (run live tests).
