# UEC Mobile ↔ API — Frontend Integration Handoff

**Audience:** the Vue / Ionic Vue (Capacitor) engineer connecting the Android and iOS apps to the live backend.
**Backend version:** API v1 (this repository). **Contract source:** [`backend-handoff.md`](backend-handoff.md), implemented as specified except where §0 says otherwise.
**Machine-readable spec:** [`docs/api/openapi.yaml`](api/openapi.yaml), also served at `GET /api/v1/openapi.yaml` and as an interactive reference at `https://app.<domain>/api-docs` (admin login required).

Everything below describes **implemented, tested** behaviour (103 automated tests). Nothing here is hypothetical.

---

## 0. What differs from (or adds to) the original proposal — read first

| # | Area | Proposal | Implemented behaviour | App action |
|---|---|---|---|---|
| 1 | `POST /auth/otp/resend` | `{ resend_in, expires_in }` | Wrapped like every success: `{ "data": { "resend_in": 45, "expires_in": 600 } }` | Read from `data` |
| 2 | `GET /hymns/{id}` without `hymn.audio` | `url: null` or `[]` (proposed) | **`audio: []`** (no entries). `has_audio` stays `true` so you can show the paywall | Current schema (url required) works unchanged |
| 3 | `GET /hymns/{id}` without `hymn.solfa` | optional omission | **`solfa: null`**; `has_solfa` stays `true` | Treat `null` + `has_solfa` as "premium locked" |
| 4 | `GET /app/config` → `payment_providers` | `["paystack","monnify"]` | Only providers that are enabled by admins **and** have credentials, in admin-chosen order. **May be `[]`** before keys are installed | Hide or disable "Subscribe" when empty |
| 5 | `GET /app/config` | — | Two **additive** fields: `maintenance_message`, `update_message` (string or null) | Optional banners; ignore if unused |
| 6 | `POST /me/devices` | response unspecified | **204** | — |
| 7 | `POST /feedback` | response unspecified | **201** `{ data: { id, status: "received" } }` | — |
| 8 | Feedback `type` | not enumerated in designs | Any short slug (`^[A-Za-z0-9_-]{1,40}$`), stored lower-case | Send your dropdown keys |
| 9 | `PUT /me/programs/{id}` | 201 / 204 | Also **204 with no change** when the incoming `updated_at` is older than the server copy (stale write ignored). **404** if the UUID belongs to another account | Keep LWW merge client-side |
| 10 | `PUT /me/favourites` | full replacement | Unknown or deleted ids are **dropped silently** (not 422), so stale local favourites never block sync | — |
| 11 | `POST /me/subscription/verify` | `success, pending, failed, abandoned` | Same set; a refunded payment reports `failed` | — |
| 12 | Extra error codes | — | `ACCOUNT_SUSPENDED` (403), `SUBSCRIPTION_NOT_FOUND` (404), `SUBSCRIPTION_NOT_ACTIVE` (409), `PAYMENT_PROVIDER_ERROR` (502), `GOOGLE_UNAVAILABLE` (503), `UNAUTHENTICATED` (401), `NOT_FOUND` (404), `RATE_LIMITED` / `TOO_MANY_ATTEMPTS` / `OTP_RESEND_TOO_SOON` (429), `PAYLOAD_TOO_LARGE` (413) | See §F |
| 13 | `events[].share_url` | string | **`string \| null`**: only set when an editor provides a link | Make the share link optional |
| 14 | Devotional future dates | **[CONFIRM]** | Hidden by default (entries after today, Africa/Lagos, return 404 and are excluded from lists). Offline bundles contain the **whole** edition so premium readers can read day by day offline | Keep hiding future dates in the UI |
| 15 | Archive editions | reserved | Editions/manuals marked premium by admins require `archive.editions` (entries, lessons, manual detail → `403 PREMIUM_REQUIRED`) | Open the paywall on 403 |
| 16 | Push deep links | — | Admin pushes are validated against your allow-list. System reminders use `/devotional/read/{YYYY-MM-DD}` and `/sunday-school` | — |
| 17 | `user.roles` | `["member"]` | Members get `["member"]`; staff accounts may include admin roles. Never gate app features on roles | — |

---

## A. Backend connection details

### Base URLs
| Environment | `VITE_API_BASE_URL` |
|---|---|
| Development | `http://<your-machine>:8000/api/v1` (`php artisan serve`, `UEC_SURFACE=all`) |
| Staging | `https://api-staging.domainname.org/api/v1` |
| Production | `https://api.domainname.org/api/v1` |

Run the app with `VITE_API_MODE=live` (`npm run dev:live`). HTTPS is required outside development.

### Headers the app sends (all honoured)
| Header | Value | Used for |
|---|---|---|
| `Accept` | `application/json` | forced server-side anyway |
| `Content-Type` | `application/json` (multipart for avatar/feedback) | |
| `Authorization` | `Bearer <access_token>` | protected endpoints; **optional** on hymn detail, devotional/SS reading and feedback (enables entitlements / links the user) |
| `Accept-Language` | `en` \| `pcm` \| `ig` | server messages and emails. Pidgin/Igbo currently fall back to English text |
| `X-App-Version` | e.g. `1.0.0` | analytics (version adoption), feedback context |
| `X-Platform` | `ios` \| `android` \| `web` | analytics (platform split), session labels |

**CORS** allows `capacitor://localhost`, `https://localhost` and `ionic://localhost` plus configured web origins. Exposed headers: `ETag`, `Retry-After`.

### Response conventions
- Success: `{ "data": … }`; `/branches` adds `meta: { current_page, last_page, per_page, total }`.
- Errors: `{ "message": string, "code"?: string, "errors"?: { field: string[] } }`.
- Keys are snake_case. Your Axios layer already converts them to camelCase.
- Dates `YYYY-MM-DD`, timestamps `2026-01-01T09:00:00Z`, money in **kobo** (integer) with `currency: "NGN"`.
- IDs are opaque strings (26-char lowercase ULIDs, e.g. `01m7d64pa3773yjwdszqssw5tv`). **Do not** parse them or assume the `h23` / `usr_…` shapes used in the mock data. Program IDs remain client-generated UUID v4.

## B. Endpoint reference

Full request/response schemas and every error response are in the OpenAPI file. Summary (🔒 = bearer required, ◐ = bearer optional):

| # | Method & path | Auth | Request | Success |
|---|---|---|---|---|
| 1 | `POST /auth/register` | — | `{channel:"phone",phone,password}` or `{channel:"email",email,password}` | 201 `data: Challenge` |
| 2 | `POST /auth/register/verify` | — | `{verification_id, code, device_name?}` | 201 `data: {user, tokens}` |
| 3 | `POST /auth/otp/resend` | — | `{verification_id}` | 200 `data: {resend_in, expires_in}` |
| 4 | `POST /auth/login` | — | `{channel, identifier, password, device_name?}` | 200 `data: {user, tokens}` |
| 5 | `POST /auth/google` | — | `{id_token, device_name?}` | 200 `data: {user, tokens}` |
| 6 | `POST /auth/refresh` | — | `{refresh_token}` | 200 `data: Tokens` |
| 7 | `POST /auth/logout` | 🔒 | `{refresh_token?}` | 204 |
| 8 | `POST /auth/password/forgot` | — | `{channel, identifier}` | 200 `data: Challenge` (always) |
| 9 | `POST /auth/password/verify` | — | `{verification_id, code}` | 200 `data: {reset_token}` |
| 10 | `POST /auth/password/reset` | — | `{reset_token, password, password_confirmation}` | 204 |
| 11 | `GET /me` | 🔒 | — | `data: User` |
| 12 | `PATCH /me` | 🔒 | `{name: string\|null}` (≤ 80) | `data: User` |
| 13 | `POST /me/avatar` | 🔒 | multipart `avatar` (jpeg/png/webp/heic ≤ 5 MB) | `data: User` |
| 14 | `POST /me/contact` | 🔒 | `{channel, value}` | 201 `data: Challenge` |
| 15 | `POST /me/contact/verify` | 🔒 | `{verification_id, code}` | `data: User` |
| 16 | `DELETE /me` | 🔒 | `{confirmation:"DELETE"}` | 204 |
| 17 | `POST /me/devices` | 🔒 | `{token, platform, app_version?, locale?}` | 204 |
| 18 | `DELETE /me/devices/{token}` | 🔒 | URL-encode the token | 204 |
| 19 | `GET /app/config` | — | — | `data: AppConfig` |
| 20 | `GET /pages/{about\|anthem\|doctrine\|terms\|privacy}` | — | — | `data: Page` |
| 21 | `GET /hymns` | — | `If-None-Match` | `data: {version, categories, hymns}` / 304 |
| 22 | `GET /hymns/{id}` | ◐ | — | `data: HymnDetail` |
| 23 | `GET /devotionals/editions` | — | — | `data: Edition[]` |
| 24 | `GET /devotionals/editions/{year}/entries?month=` | ◐ | — | `data: EntrySummary[]` |
| 25 | `GET /devotionals/entries/{date}` | ◐ | — | `data: Entry` / 404 |
| 26 | `GET /devotionals/editions/{year}/download` | 🔒 + `offline.downloads` | — | `data: {edition, entries}` |
| 27 | `GET /me/devotional-progress?year=` | 🔒 | — | `data: {read_dates}` |
| 28 | `PUT /me/devotional-progress/{date}` | 🔒 | no body | 204 |
| 29 | `GET /sunday-school/manuals` | — | — | `data: Manual[]` |
| 30 | `GET /sunday-school/manuals/{year}` | ◐ | — | `data: {manual, lessons, plan}` |
| 31 | `GET /sunday-school/lessons/{id}` | ◐ | — | `data: Lesson` |
| 32 | `GET /sunday-school/manuals/{year}/download` | 🔒 + `offline.downloads` | — | `data: {manual, lessons, plan}` |
| 33 | `GET /sunday-school/search?q=` | — | — | `data: LessonSummary[]` (≤ 50) |
| 34 | `GET /bible/versions` | — | — | `data: BibleVersion[]` |
| 35 | `GET /bible/{version}/chapters/{USFM}/{chapter}` | — | — | `data: {version, book_id, chapter, verses}` |
| 36 | `GET /events?year=` | — | — | `data: Event[]` |
| 37 | `GET /events/{id}` | — | — | `data: Event` |
| 38 | `GET /branches?q=&page=&per_page=` | — | — | `data: Branch[]`, `meta` |
| 39 | `GET /search?q=` | — | — | `data: {hymns, devotionals, lessons, events}` (≤ 10 each) |
| 40 | `GET /me/favourites` | 🔒 | — | `data: {hymn_ids, lesson_ids}` |
| 41 | `PUT /me/favourites` | 🔒 | `{hymn_ids, lesson_ids}` | 204 |
| 42 | `GET /me/programs` | 🔒 | — | `data: Program[]` |
| 43 | `PUT /me/programs/{uuid}` | 🔒 | `{name, date?, hymn_ids, updated_at}` | 201 / 204 |
| 44 | `DELETE /me/programs/{uuid}` | 🔒 | — | 204 |
| 45 | `GET /subscription/plans` | — | — | `data: Plan[]` |
| 46 | `GET /me/subscription` | 🔒 | — | `data: {subscription\|null, entitlements}` |
| 47 | `POST /me/subscription/checkout` | 🔒 | `{plan_id, provider, callback_url?}` | `data: {reference, provider, authorization_url, expires_at}` |
| 48 | `POST /me/subscription/verify` | 🔒 | `{reference}` | `data: {status, subscription\|null}` |
| 49 | `GET /me/subscription/change-preview?plan_id=` | 🔒 | — | `data: {plan, current_plan, effective_date, amount}` |
| 50 | `POST /me/subscription/change` | 🔒 | `{plan_id}` | `data: Subscription` |
| 51 | `POST /me/subscription/cancel` | 🔒 | `{reason?}` | `data: Subscription` |
| 52 | `POST /feedback` | ◐ | multipart `type, message, contact_consent?, attachments[]` (≤ 4, ≤ 5 MB, images/PDF) | 201 |
| W3 | `GET /payments/callback?reference=` | — | (browser) | 302 → `uecapp://payment/callback?reference=…` |

### Validation rules worth mirroring in the UI
| Field | Rule |
|---|---|
| `phone` | E.164 Nigerian mobile `^\+234[789][01]\d{8}$` (local `0812…` is normalised server-side) |
| `email` | RFC, ≤ 191, case-insensitive uniqueness |
| `password` | ≥ 8, letters **and** numbers, ≤ 128; breached passwords rejected (`errors.password`) |
| `code` | exactly 6 digits |
| `name` | ≤ 80 (HTML stripped) |
| program `name` | required ≤ 80; `hymn_ids` ≤ 100 existing ids, distinct, order kept |
| favourites | ≤ 2,000 ids per list |
| feedback | `message` 3–5,000 chars; ≤ 4 files; images/PDF ≤ 5 MB, type checked by content |

### Sample exchanges
```http
POST /api/v1/auth/login
{ "channel": "email", "identifier": "praise@example.com", "password": "Grace2026x", "device_name": "UEC ios app" }

200 OK
{ "data": {
  "user": { "id": "01m7d64pa3773yjwdszqssw5tv", "name": null, "phone": null, "email": "praise@example.com",
            "phone_verified_at": null, "email_verified_at": "2026-11-14T14:07:20Z", "avatar_url": null,
            "is_premium": false, "roles": ["member"], "created_at": "2026-11-14T14:07:20Z" },
  "tokens": { "access_token": "1|bz2ZVC…", "refresh_token": "rt_bixy7…", "expires_in": 900, "token_type": "Bearer" } } }
```
```http
POST /api/v1/auth/login   (unverified account)
403 { "message": "Please verify your account. We have sent you a new code.", "code": "ACCOUNT_UNVERIFIED",
      "verification": { "verification_id": "ver_01m7…", "channel": "email", "destination_masked": "pr•••@example.com", "expires_in": 600, "resend_in": 45 } }
→ show the OTP screen; submit to POST /auth/register/verify with that verification_id.
```
```http
GET /api/v1/devotionals/editions/2026/download   (free member)
403 { "message": "This feature requires a UEC Premium subscription.", "code": "PREMIUM_REQUIRED" }
```

## C. Authentication integration
1. **Sign-up:** `register` → OTP screen (6 boxes, resend button disabled for `resend_in` seconds) → `register/verify` → store tokens → `GET /me` is optional (the verify response already has the user).
2. **Login:** `login`. On `403 ACCOUNT_UNVERIFIED`, use `verification` as in sign-up; on `403 ACCOUNT_SUSPENDED`, show the message and stay logged out.
3. **Google:** obtain the ID token with the native Google SDK using the **web client ID** as `serverClientId` (Android) or the iOS client ID. POST it to `/auth/google`. The backend accepts tokens issued for the web, iOS and Android client IDs it is configured with.
4. **Tokens:** keep both in Keychain/Keystore. The access token lives 15 minutes; on any `401` call `/auth/refresh` **once** (single-flight, as your client already does) and retry. On `401 REFRESH_INVALID`, clear the session and go to login. **Always replace the stored refresh token** with the new one from every refresh: re-using an old one logs the user out on every device (theft protection).
5. **Logout:** `DELETE /me/devices/{token}` first (while still authenticated), then `POST /auth/logout {refresh_token}`, then clear local state.
6. **Password recovery:** `password/forgot` (always 200) → OTP → `password/verify` → `reset_token` (single use, 15 min) → `password/reset`. All sessions are revoked, so send the user to login.
7. **Contact change:** `POST /me/contact` → OTP → `POST /me/contact/verify` returns the updated user with `*_verified_at` set.
8. **OTP errors:** `422 errors.code` (wrong, attempts remain) · `429 OTP_LOCKED` (5 wrong attempts, start again) · `410 OTP_EXPIRED` (offer resend; an unknown id also returns 410) · `429 OTP_RESEND_TOO_SOON` with `Retry-After`.

## D. Data, types and state

### TypeScript interfaces (wire format, snake_case)
```ts
export type Iso = string;      // "2026-01-01T09:00:00Z"
export type YMD = string;      // "2026-01-05"
export type Kobo = number;

export interface ApiError { message: string; code?: string; errors?: Record<string, string[]>; verification?: Challenge }
export interface Envelope<T> { data: T }
export interface Paginated<T> { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

export interface Challenge { verification_id: string; channel: 'phone' | 'email'; destination_masked: string; expires_in: number; resend_in: number }
export interface Tokens { access_token: string; refresh_token: string; expires_in: number; token_type: 'Bearer' }
export interface User {
  id: string; name: string | null; phone: string | null; email: string | null;
  phone_verified_at: Iso | null; email_verified_at: Iso | null; avatar_url: string | null;
  is_premium: boolean; roles: string[]; created_at: Iso;
}
export interface AuthResult { user: User; tokens: Tokens }

export interface AppConfig {
  donation_url: string | null; min_supported_version: string; latest_version: string;
  store_urls: { android: string | null; ios: string | null };
  payment_providers: Array<'paystack' | 'monnify'>; support_email: string | null;
  maintenance_message: string | null; update_message: string | null;
}
export interface Page { slug: string; title: string; body: string; updated_at: Iso }

export interface HymnSummary { id: string; number: number; title: string; first_line: string; categories: string[]; has_audio: boolean; has_solfa: boolean }
export interface HymnCatalogue { version: Iso; categories: Array<{ slug: string; name: string }>; hymns: HymnSummary[] }
export interface Stanza { type: 'verse' | 'chorus' | 'refrain' | 'bridge'; number: number | null; lines: string[] }
export type Solfa =
  | { format: 'text'; key: string | null; time_signature: string | null; sections: Array<{ label: string; lines: string[] }> }
  | { format: 'image'; image_url: string; width: number | null; height: number | null };
export interface AudioTrack { id: string; label: string; url: string; duration_seconds: number; size_bytes: number }
export interface HymnDetail extends HymnSummary {
  stanzas: Stanza[]; solfa: Solfa | null; audio: AudioTrack[];
  author: string | null; composer: string | null; tune: string | null; meter: string | null; key: string | null; tempo: string | null;
  about: string | null; scripture_refs: string[]; tags: string[]; updated_at: Iso;
}

export interface Edition { year: number; title: string; theme: string | null; entry_count: number; size_bytes: number; published_at: Iso | null; is_premium: boolean }
export interface EntrySummary { date: YMD; title: string; passage: string }
export interface Entry { date: YMD; edition_year: number; title: string; passage: string; memory_verse: { text: string; reference: string }; body: string; declaration: string | null; prayer: string | null }
export interface EditionBundle { edition: Edition; entries: Entry[] }

export interface Manual { year: number; title: string; theme: string | null; lesson_count: number; size_bytes: number; published_at: Iso | null; is_premium: boolean }
export interface LessonSummary { id: string; manual_year: number; number: number; title: string; passage: string }
export interface Lesson extends LessonSummary {
  memory_verse: { text: string; reference: string }; aim: string; introduction: string;
  outline: Array<{ heading: string; body: string }>; applications: string[]; conclusion: string; revision_questions: string[];
}
export interface PlanWeek { date: YMD; week: number; lesson_id: string | null; lesson_number: number | null; description: string }
export interface ManualDetail { manual: Manual; lessons: LessonSummary[]; plan: PlanWeek[] }
export interface ManualBundle { manual: Manual; lessons: Lesson[]; plan: PlanWeek[] }

export interface BibleChapter { version: string; book_id: string; chapter: number; verses: Array<{ number: number; text: string }> }
export interface UecEvent { id: string; title: string; start_date: YMD; end_date: YMD | null; start_time: string | null; description: string; location: string | null; category: string | null; share_url: string | null }
export interface Branch { id: string; name: string; address: string; city: string | null; state: string | null; phone: string | null; latitude: number | null; longitude: number | null }
export interface SearchResults { hymns: HymnSummary[]; devotionals: EntrySummary[]; lessons: LessonSummary[]; events: UecEvent[] }

export interface Favourites { hymn_ids: string[]; lesson_ids: string[] }
export interface Program { id: string; name: string; date: YMD | null; hymn_ids: string[]; updated_at: Iso }

export type Entitlement = 'hymn.audio' | 'hymn.solfa' | 'offline.downloads' | 'archive.editions';
export interface Plan { id: string; name: string; interval: 'month' | 'six_months' | 'year'; amount: Kobo; currency: 'NGN'; savings_percent: number | null; highlighted: boolean }
export interface Subscription {
  id: string; status: 'active' | 'cancelled' | 'past_due' | 'expired'; plan: Plan;
  started_at: Iso; current_period_end: Iso; next_billing_date: Iso | null; cancelled_at: Iso | null; access_until: Iso | null;
  payment_method: { type: 'card'; label: string } | null; pending_plan: Plan | null; pending_plan_effective_at: Iso | null;
}
export interface SubscriptionState { subscription: Subscription | null; entitlements: Entitlement[] }
export interface Checkout { reference: string; provider: 'paystack' | 'monnify'; authorization_url: string; expires_at: Iso | null }
export interface VerifyResult { status: 'success' | 'pending' | 'failed' | 'abandoned'; subscription: Subscription | null }
export interface ChangePreview { plan: Plan; current_plan: Plan; effective_date: YMD; amount: Kobo }
```

### Pagination, filtering, sorting
Only `/branches` paginates (`page`, `per_page` ≤ 100, default 30; alphabetical; `q` matches name, address, city and state). Catalogue-style endpoints return complete lists by design (hymns, editions, manuals, plan), and search endpoints cap results (50 / 10 per group).

### State & sync recommendations
- **Server-authoritative:** `user`, `subscription` + `entitlements` (refetch after checkout/verify/change/cancel, on app resume, and on any `403 PREMIUM_REQUIRED`), plans, config.
- **Local-first, synced:** favourites (union merge, then full-replacement `PUT`), programs (LWW by `updated_at`, idempotent `PUT`), reading progress (`PUT` per date, idempotent). Queue writes while offline and replay them; all are safe to retry.
- **Cache with revalidation:** the hymn catalogue with `ETag`; pages (1 h); Bible chapters (7 days, immutable); editions/manuals/entries (~10 min).
- **Signed media URLs** (hymn audio, solfa images) expire after ~15 minutes. Request the hymn detail again just before streaming or downloading if the cached URL is older than that. Downloaded files can be kept offline.

## E. Third-party integration instructions
| Integration | Initiated by | Frontend responsibility |
|---|---|---|
| **Paystack / Monnify checkout** | Backend (`/checkout`) | Open `authorization_url` in the in-app browser (Capacitor Browser). Listen for the `uecapp://payment/callback?reference=…` deep link (W3 redirect), close the browser, then poll `POST /me/subscription/verify` up to ~6 times over ~15 s while `pending`. Use `payment_providers[0]`. **No gateway SDK or public key is needed in the app.** |
| **Firebase push** | Backend sends | Add `google-services.json` / `GoogleService-Info.plist` (from UEC). Request permission after sign-in, then `POST /me/devices`; re-POST when FCM rotates the token or the app version changes. Handle `data.route` (allow-listed paths) on tap from cold start and background. `data.type` values: `announcement`, `dde_daily`, `ssm_reminder`, `subscription_started`, `subscription_renewal_failed`, `subscription_expired`. |
| **Google Sign-In** | App SDK → backend verifies | Configure the web/iOS/Android client IDs (from UEC) and the reversed iOS client ID URL scheme; send the ID token to `/auth/google`. |
| **Avatars (Cloudinary)** | Backend uploads | Send the image as multipart `avatar`; display the returned `avatar_url` (already square 512 px, format/quality optimised). |
| **WhatsApp OTP / ZeptoMail** | Backend only | Nothing. Phone OTPs arrive in WhatsApp (copy-code button), email OTPs by email. |
| **Giving** | Website | Open `donation_url` from config in the in-app browser. |

## F. Error handling
| Status / code | Meaning | App behaviour |
|---|---|---|
| 401 `UNAUTHENTICATED` | Access token missing or expired | Refresh once, retry |
| 401 `REFRESH_INVALID` | Refresh token invalid, expired or reused | Clear the session → login |
| 403 `PREMIUM_REQUIRED` | Entitlement missing | Open the paywall |
| 403 `ACCOUNT_UNVERIFIED` | Login on an unverified account | OTP screen with `verification` |
| 403 `ACCOUNT_SUSPENDED` | Admin suspended the account | Show the message; no retry |
| 404 `NOT_FOUND` | Missing resource / no reading that day | Empty state ("No reading for this day") |
| 404 `SUBSCRIPTION_NOT_FOUND` | Change/cancel without a subscription | Refetch state |
| 409 `ALREADY_SUBSCRIBED` / `SAME_PLAN` / `SUBSCRIPTION_NOT_ACTIVE` | Business conflicts | Show `message` |
| 410 `OTP_EXPIRED` / `RESET_EXPIRED` | Expired code or reset token | Offer a new code / restart the flow |
| 413 `PAYLOAD_TOO_LARGE` | JSON body > 1 MB | Should not happen with normal use |
| 422 | Validation | Show `errors[field][0]` under each field; `message` = first error |
| 429 `RATE_LIMITED` / `OTP_LOCKED` / `TOO_MANY_ATTEMPTS` / `OTP_RESEND_TOO_SOON` | Throttled | Respect `Retry-After`; your client retries 429 with back-off, but **don't** auto-retry `OTP_LOCKED` |
| 502 `PAYMENT_PROVIDER_ERROR` | Gateway unreachable at checkout | "Try again"; nothing was charged |
| 503 `GOOGLE_UNAVAILABLE` / maintenance | Temporary | Retry with back-off |
| 5xx | Server error (generic message, no details) | Retry with back-off; show the cached copy |

Network failures: show cached content (your current behaviour); writes are idempotent and safe to replay.

Payment edge cases:
- **Browser closed before paying:** verify returns `abandoned` or `pending`; show "not completed".
- **Paid, webhook late:** verify asks the gateway itself and returns `success`.
- **App crashed after paying:** the webhook activates the subscription, and `GET /me/subscription` reflects it on next launch.

## G. Mobile-specific requirements
- **Device identification:** the FCM token is the device key. `platform` must be `ios` or `android`. Send `app_version` and `locale`.
- **Version checks:** compare `X-App-Version` with `min_supported_version` / `latest_version` from config (the force-update screen is reserved). Store URLs come from config.
- **Deep links:** `uecapp://payment/callback?reference=…` (payments). Universal links (`apple-app-site-association`, `assetlinks.json`) are **not** hosted yet; this needs the app-link domain decision.
- **Uploads:** compress large photos before upload where possible (the server limit is 5 MB). HEIC is accepted when Cloudinary is configured, so convert to JPEG on device for the most robust path.
- **Offline:** bundles are large JSON (≈1–2 MB gzipped per edition), so download on Wi-Fi when possible. Bundles include every published entry of the edition.
- **Time zone:** devotional "today" and event times use Africa/Lagos.

## H. Feature integration checklist
- [ ] Switch to `VITE_API_MODE=live` against staging; zero `API contract mismatch` logs across all screens
- [ ] Update ID handling (ULIDs) and the four schema changes in §0 (#1, #2/#3, #4/#5, #13)
- [ ] Email sign-up → email OTP → verified → home
- [ ] Phone sign-up → WhatsApp OTP (needs WhatsApp configured on staging)
- [ ] Wrong OTP ×5 → locked; resend after 45 s; expiry → 410
- [ ] Login (phone & email), wrong password, unverified account flow, suspended account message
- [ ] Google sign-in on a real Android and iOS device
- [ ] Token expiry → silent refresh; reused refresh token → logout
- [ ] Forgot password for known and unknown accounts; reset logs out other sessions
- [ ] Profile name, avatar (camera & gallery), add/change email and phone with OTP
- [ ] Delete account → logged out; subscription cancelled
- [ ] Push permission after login → device registered; logout removes it; tap routing cold/background
- [ ] Hymn catalogue ETag revalidation; detail for free (no audio/solfa) vs premium (audio plays; signed URL refresh)
- [ ] Devotional today/month/date picker; 404 state; reading progress synced across two devices
- [ ] Sunday School plan "this week", lesson detail, search, favourites sync
- [ ] Bible chapter navigation (after KJV import on staging)
- [ ] Events by year, share (null-safe), add to calendar; branch search + pagination + map fallback when no coordinates
- [ ] Global search groups
- [ ] Programs create/rename/reorder/delete, sync between devices
- [ ] Feedback with 0–4 attachments as guest and as member
- [ ] Paywall plans; checkout (Paystack test card) → deep link → verify → entitlements unlock; abandoned checkout path
- [ ] Monnify checkout (if enabled)
- [ ] Change plan preview + confirm (pending plan shown); cancel → access until date shown
- [ ] Offline downloads of an edition and a manual as premium; read in airplane mode
- [ ] Give tab opens `donation_url`

## I. API testing resources
- **OpenAPI 3.1:** `docs/api/openapi.yaml` (import into Postman or Insomnia: *Import → OpenAPI*, which generates a full collection). No separate Postman collection is maintained, to avoid drift.
- **Interactive reference:** `https://app.<domain>/api-docs`.
- **Staging accounts:** create your own through the app (email OTPs arrive in your inbox once ZeptoMail is configured; on a local backend with `MAIL_MAILER=log` they are written to `storage/logs/laravel-*.log`). For premium testing, use Paystack test mode with card `4084 0840 8408 4081`, any future expiry, CVV `408`, PIN `0000`, OTP `123456`.
- **Local backend:** see [DEPLOYMENT.md §11](DEPLOYMENT.md#11-local-development). It serves the API at `http://localhost:8000/api/v1`.
- **Contacts:** backend questions → backend team. Content (hymns, devotionals, lessons, events, branches, pages) is entered by UEC editors in the dashboard; ask them for staging content.
