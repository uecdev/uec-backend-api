# UEC Backend — Authentication, Authorization, Security & Operations

**Audience:** engineers, security reviewers and the operations owner.

## 1. Authentication

### Mobile API (stateless)
| Mechanism | Detail |
|---|---|
| Access token | Sanctum personal access token, **15 min** (`UEC_ACCESS_TOKEN_TTL`). Sent as `Authorization: Bearer …`. Sanctum is token-only (`config/sanctum.php` → `guard => []`), so session cookies are never consulted. |
| Refresh token | Opaque `rt_…` (80 random chars), **60 days**, stored as SHA-256. Rotated on every `/auth/refresh`; the old access token is deleted. Presenting a revoked token **revokes the whole family** and writes `security.refresh_token_reuse`. |
| Revocation | Logout revokes the session. Password reset, account deletion, admin suspension and admin "sign out everywhere" revoke every session. |
| Passwords | bcrypt (12 rounds). Policy: ≥ 8 characters, letters + numbers, rejected if found in Have I Been Pwned (k-anonymity range API; fails open if unreachable). |
| OTP | 6 digits, 10-minute validity, resend after 45 s, max 5 attempts → `OTP_LOCKED`, max 5 resends. Codes are HMAC-SHA256 hashed with the app key, compared in constant time, single use, and never logged (`#[SensitiveParameter]`). Pending registrations are stored encrypted in Redis, not in `users`. |
| OTP pumping protection | ≤ 6 sends per destination per hour and ≤ 30 per IP per hour (configurable), on top of route throttles. |
| Enumeration safety | Forgot-password always returns 200 with a challenge (no code is sent for unknown accounts). Login errors are generic. A dummy bcrypt check equalises timing. |
| Google | ID token verified server-side: RS256 signature against Google JWKS (cached per `Cache-Control`), `aud` ∈ configured client IDs, `iss`, expiry (60 s leeway), `email_verified`. Links by `sub`, then by verified email. |

### Admin dashboard (session)
- Email + password login (Livewire). Only users with an admin role who are not suspended can sign in. Failed attempts are rate-limited (5 per 5 minutes per IP + email) and audited.
- Sessions use Redis, are encrypted, use a host-only cookie on `app.<domain>`, `Secure`, `SameSite=Lax`, and regenerate on login. CSRF is enforced by the `web` middleware.
- Administrators are created with `php artisan uec:admin:create` (password ≥ 12 characters with mixed case, numbers and symbols) or from **Admins & roles** with a one-time generated password that is shown once.

## 2. Authorization

### Members
- Every `/me/*` query is scoped to the authenticated user.
- Program upserts reject IDs owned by another user (404), and deletes are scoped by `user_id`.
- Premium features are enforced on the server: offline bundles return `403 PREMIUM_REQUIRED`, hymn audio is returned as signed URLs only with `hymn.audio`, solfa only with `hymn.solfa`, and archive editions need `archive.editions`.

### Administrators — role → permission matrix (defaults; editable by super admins)
| Permission | content_editor | finance | admin | super_admin |
|---|:-:|:-:|:-:|:-:|
| dashboard.view | ✔ | ✔ | ✔ | ✔ |
| users.view / users.manage | | view | ✔ | ✔ |
| content.view / content.manage | ✔ | | ✔ | ✔ |
| finance.view / finance.manage | | view | ✔ | ✔ |
| notifications.view / notifications.send | view | | ✔ | ✔ |
| feedback.view / feedback.manage | view | | ✔ | ✔ |
| app.manage | | | ✔ | ✔ |
| reports.view / reports.export | | ✔ | ✔ | ✔ |
| system.audit / system.integrations / system.jobs | | | ✔ | ✔ |
| system.admins | | | | ✔ |

- `super_admin` passes every check (`Gate::before`).
- Every Livewire page calls `Gate::authorize()` in `mount()`, and every mutating action re-checks its own permission.
- The navigation hides sections the user cannot access.
- The last super admin cannot be removed, and admins cannot revoke or demote themselves.

## 3. OWASP API Security Top 10 (2023) mapping
| Risk | Control |
|---|---|
| API1 Broken object-level authorization | User-scoped queries; program ownership check; payment verify returns 404 for other users' references; tests cover cross-user access |
| API2 Broken authentication | Short-lived tokens, rotating hashed refresh tokens with family revocation, OTP limits, login throttling, server-side Google verification |
| API3 Broken object property-level authorization | Explicit resource builders (no model serialisation); `$hidden` on secrets; Form Requests whitelist inputs; `forceFill` only with validated data |
| API4 Unrestricted resource consumption | Named rate limiters (`api`, `auth`, `otp`, `uploads`, `payments`, `webhooks`); 1 MB JSON limit; upload size/count limits; Nginx `limit_req`; capped list sizes (favourites ≤ 2,000, programs ≤ 100 hymns) |
| API5 Broken function-level authorization | Admin surface is a separate deployment; granular permissions on every component and action |
| API6 Unrestricted access to sensitive business flows | Checkout amount from DB; amount/currency/reference verified; OTP pumping caps; one renewal attempt per period |
| API7 SSRF | No user-supplied URLs are fetched; callback URLs are allow-listed (app scheme or configured https hosts) |
| API8 Security misconfiguration | `APP_DEBUG=false` enforced by `deploy.sh`; JSON errors never include traces; security headers (CSP, HSTS, nosniff, frame options); strict CORS allow-list |
| API9 Improper inventory management | Versioned `/api/v1`; OpenAPI spec matched to the route list; separate staging/production |
| API10 Unsafe consumption of APIs | Webhook HMAC-SHA512 verification (+ optional Paystack IP allow-list); gateway responses validated before use; timeouts and bounded retries on all outbound calls |

## 4. Input, output and file handling
- Phone and email are normalised before validation (E.164 Nigerian mobile regex, RFC email, lower-cased).
- Rich content uses the app's restricted markup. The app never renders HTML, and the dashboard preview escapes everything (`App\Support\RichText`).
- Uploads are checked by **content** (`mimetypes`, finfo), not extension. Avatars are re-encoded with GD (EXIF/GPS stripped, polyglots neutralised). Feedback files are stored on the private disk and served to admins only through permission-checked signed URLs, with `nosniff` and a restrictive CSP.
- Mass assignment: models use `$guarded`, and writes use validated arrays. `preventSilentlyDiscardingAttributes` is on outside production.
- SQL: Eloquent and bindings throughout. The only raw SQL is static aggregate expressions with no user input.

## 5. Webhooks & payments
- Signatures are verified in constant time over the raw body before parsing. Invalid signatures get 401 and a `security.webhook_signature_invalid` audit entry.
- Idempotency comes from the unique (`provider`, `event_key`) key, via `INSERT … ON CONFLICT DO NOTHING`. Processing is queued with retries and back-off.
- Card data never touches UEC servers (hosted checkout). Only gateway authorization codes are stored, encrypted (`encrypted:array` cast), with brand and last four digits for display.

## 6. Secrets & configuration
- All credentials come from environment variables (`config/services.php`). None are stored in the database; the Integrations page only reports whether each is configured.
- `.env`, `auth.json` and keys are git-ignored. CI runs a secret-pattern scan.
- Firebase service-account JSON lives outside the web root (`FIREBASE_CREDENTIALS`) or arrives base64-encoded through the environment.
- `APP_KEY` must be identical on the API and admin hosts: it encrypts sessions, subscription authorization data and OTP drafts.

## 7. Data protection (NDPA 2023)
- Personal data held: name, phone, email, avatar, reading history, favourites, programs, payment records, feedback, device tokens and activity dates.
- **Account deletion (in-app, Apple/Google requirement):** gateway subscription cancelled, sessions revoked, personal rows deleted, user row anonymised and soft-deleted immediately. Payment rows are retained, linked to the anonymised row, for financial record-keeping.
- Logs never contain passwords, OTPs, tokens or card data. `AuditLogger` redacts sensitive keys, `integration_logs` store no bodies, and `notification_logs` mask destinations.
- **[CONFIRM] with UEC:** privacy policy and terms content (editable under Pages → Terms/Privacy), retention periods, and the DPO contact.

## 8. Operational notes
- **Monitoring:**
  - `/up` (database + cache) on both subdomains for uptime checks.
  - **Operations** page: database, cache, queue backlog, failed jobs, scheduler heartbeat and storage.
  - **Integrations** page: per-provider success rates and live tests.
- **Error tracking:** Laravel logs go to `storage/logs` (daily). Sentry or Crashlytics is **[CONFIRM]** in the handoff and not yet integrated; add a log channel when chosen.
- **Backups:** see [DEPLOYMENT.md §9](DEPLOYMENT.md#9-backups--recovery).
- **Incident playbook:**
  - Suspected token theft: suspend the member, or use "Sign out everywhere".
  - Gateway outage: cancellations and plan changes are retried automatically (`SyncSubscriptionWithGateway`); stale pending payments can be re-verified from **Payments**.
  - Failed webhooks: reprocess from **Operations → Webhooks**.
- **Key rotation:** rotate gateway, ZeptoMail, WhatsApp, Cloudinary and AWS keys in `.env`, then run `php artisan optimize` and `queue:restart`. Rotating `APP_KEY` invalidates sessions, encrypted authorization data and pending OTPs; use Laravel's `APP_PREVIOUS_KEYS` for graceful rotation.
