# UEC Mobile — Backend Engineer Handoff

**Audience:** Laravel backend engineer(s) building the UEC API.
**Mobile app:** `uec-mobile-app` (Ionic Vue 9 + Capacitor 8, TypeScript).
**Document status:** v1.0 — 2026-11-13. Everything here is a **proposal** unless marked otherwise; the mobile app is built against it and can adapt, but please raise changes early.

### How to read the status tags

| Tag | Meaning |
|---|---|
| **[MANDATORY]** | The app cannot ship without it. |
| **[OPTIONAL]** | The app works without it; nice to have. |
| **[PROPOSED]** | Shape suggested by the mobile team, not yet agreed. Change it if you need to, but tell us. |
| **[CONFIRM]** | A product/business decision UEC must make before you build it. |

### The contract is executable

The app ships an in-process **mock server** (`src/mocks/`) that implements every endpoint in this document. The integration test `tests/integration/mockContract.spec.ts` runs the app's real (Zod-validated) API clients against it. To see the exact JSON the app sends and expects for any endpoint, read the matching handler in `src/mocks/handlers/*.ts` and the schema in `src/features/<module>/api.ts`.

Once your API is up, point the app at it with `VITE_API_MODE=live` and `VITE_API_BASE_URL=…` (`npm run dev:live`). Any response that doesn't match the contract shows up as a `contract` error in the app logs. That makes contract drift easy to find.

---

## Contents

- [A. Project overview](#a-project-overview)
- [B. Functional requirements](#b-functional-requirements)
- [C. API requirements](#c-api-requirements)
- [D. Authentication and user management](#d-authentication-and-user-management)
- [E. Payments and subscriptions](#e-payments-and-subscriptions)
- [F. Third-party integrations](#f-third-party-integrations)
- [G. Database requirements (proposed)](#g-database-requirements-proposed)
- [H. Notifications](#h-notifications)
- [I. Security and access control](#i-security-and-access-control)
- [J. Backend dependencies and action items](#j-backend-dependencies-and-action-items)
- [K. Integration and testing checklist](#k-integration-and-testing-checklist)

---

## A. Project overview

### A.1 Objectives
The app is a digital companion for UEC members. It offers:

- The **Daily Delight** devotional (DDE)
- The **Sunday School Manual** (SSM), with a weekly lesson plan
- The **UEC Hymnal**: lyrics, solfa, audio, favourites and service "programs"
- A **Bible** reader
- The **National Calendar of Events**
- A **branch finder**
- Church content: About, Anthem and Doctrine
- **Giving** (via an external donation page)
- A **premium subscription** that unlocks audio, solfa and offline downloads

Offline reading is a core requirement: congregations often have poor connectivity during services.

### A.2 Technology
| Layer | Technology |
|---|---|
| Mobile | Vue 3, TypeScript (strict), Ionic 9, Capacitor 8, Pinia, TanStack Query, Axios, Zod |
| Backend (yours) | Laravel (REST), PostgreSQL, Redis (cache/queues), S3/Cloudinary storage |
| Integrations | Paystack, Monnify, ZeptoMail, Firebase (FCM), Google Sign-In, WhatsApp Business, Cloudinary, Amazon S3 |

### A.3 Architecture (mobile side, relevant to you)
- One Axios client (`src/core/api/client.ts`) handles every request:
  - Bearer token on each request.
  - On a `401`, it refreshes the token once, even if several requests fail at the same moment, then retries.
  - It converts JSON keys between **snake_case** (API) and camelCase (app).
  - It turns every error into a single normalised error type.
- Every response body is validated at runtime with Zod.
- Content is cached on the device. If the network fails, the app shows the last good response.
- Premium users can download whole devotional editions and Sunday School manuals as single JSON bundles for offline use.
- Favourites, programs and reading progress are **local-first**. They sync to your API using idempotent `PUT`s when the user is signed in.

### A.4 Functional modules
Auth & account · Home · Daily Delight · Sunday School · Hymnal (+ programs, audio) · Bible · Events · Branches · Content pages · Giving · Subscription · Feedback · Settings (client-only) · Downloads (client-only) · Push notifications.

> **Note on scope:** the original brief listed modules such as "Sermons", "Ministries & departments" and "Event registrations". These **do not appear in the supplied designs**, so they are not built and have no endpoints here. **[CONFIRM]** whether they are wanted in a later phase.

---

## B. Functional requirements

Each module below lists what the user does, what the backend must provide, and the business rules.

### B.1 Authentication [MANDATORY]
- Sign up with a **phone number** (WhatsApp number; the OTP goes over WhatsApp) **or an email address**, plus a password. The account is created only after the OTP is verified.
- Log in with phone or email and password, or with **Google**.
- Forgot password: identifier → OTP → new password → success.
- Users can **browse as guests**. Guests can read free content. They need an account to subscribe, view their profile or sync data.
- Rules: OTPs are **6 digits**, expire after **10 minutes**, can be resent after **45 s**, and allow at most **5 attempts**.
- **[CONFIRM]** The design reads "6 digit password". We enforce **at least 8 characters with letters and numbers**, following NIST/OWASP. Please validate the same rule on the server.

### B.2 Profile & account [MANDATORY]
- View and edit name and avatar (camera or gallery upload).
- Add or change email or phone. The new value must be **verified by OTP** before it is saved.
- **Delete account.** Apple and Google both require in-app deletion.
  - On delete: revoke all tokens, cancel any subscription at the gateway, and anonymise or remove personal data according to the retention policy.

### B.3 Daily Delight devotional [MANDATORY]
- One entry per calendar day. Each entry has: title, main passage, memory verse (text + reference), body (rich text), declaration and prayer.
- Editions are yearly. Each has a theme, e.g. "2026: Exceeding Great Joy".
- The app shows today's entry and earlier entries this month. Users browse by month or year and pick a date from a calendar.
- **Reading progress** is stored per user per date. The app uses it for unread dots and the "read/total" month counters.
- **Offline:** a full edition downloads as one bundle (**premium**).
- **[CONFIRM]** Publication rule: can users read future dates? Currently the app hides dates after today.

### B.4 Sunday School Manual [MANDATORY]
- Yearly manual containing numbered lessons. Each lesson has:
  - title and passage
  - memory verse
  - aim and introduction
  - an outline of headed points
  - applications and conclusion
  - revision questions
- Weekly **lesson plan**: one row per Sunday with a description such as "Lesson 4: Outline 2 to 3" and a link to the lesson.
- "This week's lesson" is computed on the device from the plan: the latest Sunday on or before today.
- Lesson search across all years; favourite lessons sync to the account.
- **Offline:** a full manual downloads as one bundle (**premium**).

### B.5 Hymnal [MANDATORY]
- The full catalogue: number, title, first line, categories, `has_audio`, `has_solfa`. Searched on the device.
- Categories: Praise, Prayer, Communion, Christmas, Easter, Funeral, Thanksgiving, Morning, Evening. **[CONFIRM]** the final list.
- Each hymn's detail has:
  - ordered stanzas (verse / chorus / refrain)
  - solfa notation (text or image)
  - audio tracks
  - author, composer, tune, meter, key, tempo
  - an "About" note
  - scripture references and tags
- Favourites, and **programs** (an ordered list of hymns for a service) sync to the account.
- Premium unlocks **audio** and **solfa**. Audio files are downloadable for offline use.
- **[CONFIRM]** Hymn copyright. Public-domain texts are fine. Copyrighted hymns need licences, e.g. CCLI. Audio recordings need performance and recording rights.

### B.6 Bible [MANDATORY]
- Read by book and chapter, with a version selector.
- A quick-peek sheet shows passages referenced in devotionals and lessons.
- **KJV** is public domain. Any other version needs a licence. **[CONFIRM]** which versions.

### B.7 Events [MANDATORY]
- The national calendar: title, start/end date, optional start time, description, location. Users can add an event to their phone calendar and share it.

### B.8 Branches [MANDATORY]
- Searchable directory (name, address, city, state, phone, lat/lng). Shows a map, plus "Get directions" and "View on Maps".

### B.9 Content pages [MANDATORY]
- About UEC, UEC Anthem and UEC Doctrine, editable in your CMS/admin.

### B.10 Giving [MANDATORY]
- The design states: "You will be redirected to an external donation page on our website". The app opens `donation_url` from `GET /app/config` in an in-app browser. **Card data never touches the app.**
- **[CONFIRM]** Do donations need Paystack/Monnify inside the website (your responsibility) and receipts by email? See §E.7.

### B.11 Subscription (premium) [MANDATORY]
- Plans in the design: **Monthly ₦2,500**, **6 Months ₦12,000**, **Yearly ₦19,900 (save 34%)**.
- Users can subscribe, view status, change plan (it takes effect on the next billing date) and cancel (access continues until the period ends).
- Premium features (proposal, **[CONFIRM]**):

  | Entitlement key | Unlocks |
  |---|---|
  | `hymn.audio` | Hymn audio playback & download |
  | `hymn.solfa` | Solfa notation tab |
  | `offline.downloads` | Downloading DDE editions / SSM manuals |
  | `archive.editions` | Past-year editions (reserved) |

- Free: the current-year DDE, the current-year SSM, hymn lyrics, Bible, events, branches and content pages.
- ⚠️ **Store-policy risk.** See §E.1 before building payments.

### B.12 Feedback [MANDATORY]
- Fields: type, message, up to **4 attachments** (images or PDF, ≤ 5 MB each), and a "happy to be contacted" consent flag. Works for guests too.

### B.13 Push notifications [MANDATORY]
- Device token registration; payloads can deep-link into the app. See §H.

---

## C. API requirements

### C.1 Conventions [MANDATORY]

| Topic | Convention |
|---|---|
| Base URL | `https://<host>/api/v1`. **HTTPS only** (the app refuses `http` outside development) |
| Format | JSON, **snake_case keys**. The app converts keys automatically. ⚠️ **Never use data values as object keys** (e.g. `{ "h23": … }`); use arrays |
| Success | `{ "data": … }`. Lists may add `"meta"` |
| Pagination | Laravel style: `meta: { current_page, last_page, per_page, total }`; query `?page=&per_page=` |
| Errors | `{ "message": string, "code"?: string, "errors"?: { field: string[] } }` |
| Validation | HTTP **422**, Laravel's default `errors` bag. The app shows the message under each field |
| Auth | `Authorization: Bearer <access_token>` |
| Request headers sent by the app | `Accept: application/json`, `Accept-Language: en\|pcm\|ig`, `X-App-Version: 1.0.0`, `X-Platform: ios\|android\|web` |
| Dates | Calendar dates as `YYYY-MM-DD` (no timezone). Timestamps as ISO-8601 UTC (`2026-01-01T09:00:00Z`) |
| Money | Integers in **kobo** (₦19,900.00 → `1990000`), plus `currency: "NGN"` |
| IDs | Strings (ULID/UUID recommended). Programs use **client-generated UUID v4** |
| Rate limiting | Return **429** with `Retry-After`. The app retries 429/5xx/timeouts with backoff and fails fast on other 4xx |

**Status codes the app understands:** `200`, `201`, `204`, `401` (expired or invalid token → refresh), `403` (forbidden; use `code` such as `PREMIUM_REQUIRED`), `404`, `409` (conflict, e.g. `ALREADY_SUBSCRIBED`, `SAME_PLAN`), `410` (expired OTP or reset token), `422`, `429`, `5xx`.

**Error `code` values the app reacts to:**

| Code | Status | App behaviour |
|---|---|---|
| `ACCOUNT_UNVERIFIED` | 403 | On login: include `verification` (a challenge, §D.1). The app opens the OTP screen |
| `PREMIUM_REQUIRED` | 403 | Opens the paywall |
| `OTP_EXPIRED` / `RESET_EXPIRED` | 410 | Shows the message; the user requests a new code |
| `OTP_LOCKED` | 429 | Shows the message |
| `ALREADY_SUBSCRIBED`, `SAME_PLAN` | 409 | Shows the message |
| `REFRESH_INVALID` | 401 | Forces logout |

### C.2 Rich text format [MANDATORY]
Devotional bodies, lesson sections, hymn "about" notes and content pages are plain strings in a **restricted markup**. The app renders them without HTML, which prevents XSS:

```
Paragraphs are separated by a blank line.
**bold**   *italic*
[label](bible:Joshua 1:1-4)     → tappable scripture reference (opens the Bible sheet)
[label](https://example.org)    → external link (https only)
```

HTML in content is shown as literal text. Your admin editor should store this format, or convert to it.

### C.3 Endpoint index

Auth column: — = public, 🔒 = bearer token required. The default payload source is `src/mocks/handlers/*.ts`.

| # | Method | Path | Auth | Purpose |
|---|---|---|---|---|
| 1 | POST | `/auth/register` | — | Start sign-up (sends OTP) |
| 2 | POST | `/auth/register/verify` | — | Verify OTP → create account + tokens |
| 3 | POST | `/auth/otp/resend` | — | Resend an OTP for any challenge |
| 4 | POST | `/auth/login` | — | Phone/email + password → tokens |
| 5 | POST | `/auth/google` | — | Google ID token → tokens |
| 6 | POST | `/auth/refresh` | — | Rotate refresh token → new tokens |
| 7 | POST | `/auth/logout` | 🔒 | Revoke refresh token |
| 8 | POST | `/auth/password/forgot` | — | Start reset (sends OTP) |
| 9 | POST | `/auth/password/verify` | — | Verify OTP → reset token |
| 10 | POST | `/auth/password/reset` | — | Set new password |
| 11 | GET | `/me` | 🔒 | Current user |
| 12 | PATCH | `/me` | 🔒 | Update name |
| 13 | POST | `/me/avatar` | 🔒 | Upload avatar (multipart) |
| 14 | POST | `/me/contact` | 🔒 | Add/change email or phone (sends OTP) |
| 15 | POST | `/me/contact/verify` | 🔒 | Verify contact change |
| 16 | DELETE | `/me` | 🔒 | Delete account |
| 17 | POST | `/me/devices` | 🔒 | Register FCM token |
| 18 | DELETE | `/me/devices/{token}` | 🔒 | Unregister FCM token |
| 19 | GET | `/app/config` | — | Remote config (donation URL, versions, providers) |
| 20 | GET | `/pages/{slug}` | — | About / anthem / doctrine |
| 21 | GET | `/hymns` | — | Full hymn catalogue |
| 22 | GET | `/hymns/{id}` | — | Hymn detail |
| 23 | GET | `/devotionals/editions` | — | DDE editions |
| 24 | GET | `/devotionals/editions/{year}/entries?month=` | — | Entry summaries |
| 25 | GET | `/devotionals/entries/{date}` | — | Full entry |
| 26 | GET | `/devotionals/editions/{year}/download` | 🔒* | Full edition bundle (premium) |
| 27 | GET | `/me/devotional-progress?year=` | 🔒 | Read dates |
| 28 | PUT | `/me/devotional-progress/{date}` | 🔒 | Mark read (idempotent) |
| 29 | GET | `/sunday-school/manuals` | — | Manuals |
| 30 | GET | `/sunday-school/manuals/{year}` | — | Lessons + plan |
| 31 | GET | `/sunday-school/lessons/{id}` | — | Full lesson |
| 32 | GET | `/sunday-school/manuals/{year}/download` | 🔒* | Full manual bundle (premium) |
| 33 | GET | `/sunday-school/search?q=` | — | Lesson search |
| 34 | GET | `/bible/versions` | — | Available versions |
| 35 | GET | `/bible/{version}/chapters/{book}/{chapter}` | — | Chapter verses |
| 36 | GET | `/events?year=` | — | Events for a year |
| 37 | GET | `/events/{id}` | — | Event detail |
| 38 | GET | `/branches?q=&page=&per_page=` | — | Paginated branch search |
| 39 | GET | `/search?q=` | — | Global search |
| 40 | GET | `/me/favourites` | 🔒 | Favourite hymns & lessons |
| 41 | PUT | `/me/favourites` | 🔒 | Replace favourites (idempotent) |
| 42 | GET | `/me/programs` | 🔒 | Hymn programs |
| 43 | PUT | `/me/programs/{id}` | 🔒 | Upsert program (idempotent) |
| 44 | DELETE | `/me/programs/{id}` | 🔒 | Delete program |
| 45 | GET | `/subscription/plans` | — | Plans |
| 46 | GET | `/me/subscription` | 🔒 | Subscription + entitlements |
| 47 | POST | `/me/subscription/checkout` | 🔒 | Initialise payment |
| 48 | POST | `/me/subscription/verify` | 🔒 | Verify payment by reference |
| 49 | GET | `/me/subscription/change-preview?plan_id=` | 🔒 | Preview plan change |
| 50 | POST | `/me/subscription/change` | 🔒 | Schedule plan change |
| 51 | POST | `/me/subscription/cancel` | 🔒 | Cancel at period end |
| 52 | POST | `/feedback` | optional 🔒 | Submit feedback (multipart) |
| W1 | POST | `/webhooks/paystack` | signature | Paystack events |
| W2 | POST | `/webhooks/monnify` | signature | Monnify events |
| W3 | GET | `/payments/callback` | — | Browser landing page → redirects to the app |

\* Downloads need a token **and** the `offline.downloads` entitlement. If the edition itself is marked free (`is_premium: false`), you may allow it without the entitlement; the app gates downloads behind premium either way. **[CONFIRM]**

### C.4 Content endpoints — request & response detail

The auth, account, payment and personal-data endpoints are detailed in §D, §E and §C.5.

#### 19. `GET /app/config` [MANDATORY]
```json
{ "data": {
  "donation_url": "https://uec.org.ng/give",
  "min_supported_version": "1.0.0",
  "latest_version": "1.2.0",
  "store_urls": { "android": "https://play.google.com/…", "ios": "https://apps.apple.com/…" },
  "payment_providers": ["paystack", "monnify"],
  "support_email": "support@…"
}}
```
`payment_providers[0]` is the provider the app uses for checkout. `min_supported_version` is reserved for a future force-update screen **[OPTIONAL]**.

#### 20. `GET /pages/{slug}` — slug ∈ `about | anthem | doctrine`
```json
{ "data": { "slug": "doctrine", "title": "UEC Doctrine", "body": "<rich text>", "updated_at": "2026-01-01T00:00:00Z" } }
```
`404` for an unknown slug. Respond with `Cache-Control: max-age=3600`.

#### 21. `GET /hymns` — full catalogue (cache 1 h, support `ETag`/`If-None-Match`)
```json
{ "data": {
  "version": "2026-11-01T10:00:00Z",
  "categories": [{ "slug": "praise", "name": "Praise" }],
  "hymns": [{ "id": "h23", "number": 23, "title": "Glory Be to God the Father",
              "first_line": "Glory be to God the Father,", "categories": ["praise"],
              "has_audio": true, "has_solfa": true }]
}}
```
Expected size is about 1 KB per 10 hymns, so the whole catalogue goes in one response with no pagination. `number` must be unique.

#### 22. `GET /hymns/{id}`
```json
{ "data": {
  "id": "h23", "number": 23, "title": "…", "first_line": "…", "categories": ["praise"],
  "has_audio": true, "has_solfa": true,
  "stanzas": [
    { "type": "verse", "number": 1, "lines": ["Glory be to God the Father,", "…"] },
    { "type": "chorus", "number": null, "lines": ["Glory, glory, glory, glory,", "…"] }
  ],
  "solfa": { "format": "text", "key": "E♭", "time_signature": "4/4",
             "sections": [{ "label": "Soprano", "lines": ["d : d | m : s | …"] }] },
  "audio": [{ "id": "h23-vocal", "label": "Vocal", "url": "https://cdn…/h23-vocal.mp3",
              "duration_seconds": 134, "size_bytes": 2150000 }],
  "author": "Horatius Bonar (1808–1889)", "composer": null, "tune": "REGENT SQUARE",
  "meter": "8.7.8.7.8.7", "key": "E♭ Major", "tempo": "Moderato",
  "about": "<rich text>", "scripture_refs": ["Revelation 5:13"], "tags": ["Praise", "Trinity"],
  "updated_at": "2026-01-01T00:00:00Z"
}}
```

**Solfa.** `solfa` can also be `{ "format": "image", "image_url": "…", "width": 1200, "height": 1800 }` (a scanned sheet), or `null`.

**Audio: entitlement enforcement.** `audio[].url` should be a **short-lived signed URL** (S3/Cloudinary, about 15 min).
- Return it only to users with the `hymn.audio` entitlement.
- For everyone else, return `audio` entries with `url: null`, or an empty array.
- **[PROPOSED]** The app currently requires `url` to be a string; we'll relax this when you confirm the approach.

**Solfa: entitlement enforcement.** Treat `hymn.solfa` the same way: omit `solfa` for non-premium users if you want server-side enforcement.

#### 23. `GET /devotionals/editions`
```json
{ "data": [{ "year": 2026, "title": "2026 Edition", "theme": "Exceeding Great Joy",
             "entry_count": 365, "size_bytes": 13600000,
             "published_at": "2025-11-01T00:00:00Z", "is_premium": false }] }
```

#### 24. `GET /devotionals/editions/{year}/entries?month=1`
Without `month`, return the whole year. With it, return just that month.

`[{ "date": "2026-01-05", "title": "Strength in Weakness", "passage": "Isaiah 40:29-31" }]`

#### 25. `GET /devotionals/entries/{date}`
```json
{ "data": {
  "date": "2026-01-01", "edition_year": 2026,
  "title": "Keys to Notable and Undeniable Good Successes",
  "passage": "Joshua 1:1-9",
  "memory_verse": { "text": "…", "reference": "Psalm 119:11" },
  "body": "In the passage today… [(v.1-4)](bible:Joshua 1:1-4)…\n\nSecond paragraph…",
  "declaration": "…", "prayer": "…"
}}
```
`404` when no entry exists for that date. The app shows a "No reading for this day" state.

#### 26. `GET /devotionals/editions/{year}/download` 🔒 premium
Response: `{ "data": { "edition": {…as #23}, "entries": [ …full entries as #25 ] } }`.

The app saves it on the device and reads offline from then on.
- Use gzip; about 1–2 MB compressed.
- **[OPTIONAL]** Alternatively respond `302` to a signed S3 URL of the same JSON. Tell us and we'll follow it.
- `403 PREMIUM_REQUIRED` if the user isn't entitled.

#### 27/28. Reading progress 🔒
- `GET /me/devotional-progress?year=2026` → `{ "data": { "read_dates": ["2026-01-01", …] } }`
- `PUT /me/devotional-progress/2026-01-05` → `204`. Idempotent: repeating it is harmless. No body.

#### 29. `GET /sunday-school/manuals`
`[{ "year": 2026, "title": "2026 Manual", "theme": "Exceeding Great Joy", "lesson_count": 17, "size_bytes": 13600000, "published_at": "…", "is_premium": false }]`

#### 30. `GET /sunday-school/manuals/{year}`
```json
{ "data": {
  "manual": { …as #29 },
  "lessons": [{ "id": "ssm-2026-1", "manual_year": 2026, "number": 1, "title": "Exceeding Great Joy", "passage": "Matthew 2:1-12" }],
  "plan": [
    { "date": "2026-01-04", "week": 1, "lesson_id": null, "lesson_number": null, "description": "Rededication of Teachers, Workers & Students" },
    { "date": "2026-01-11", "week": 2, "lesson_id": "ssm-2026-1", "lesson_number": 1, "description": "Lesson 1: Topic to Outline 1" }
  ]
}}
```
`plan[].date` is a Sunday. `week` is the week of that month (1–5).

#### 31. `GET /sunday-school/lessons/{id}`
```json
{ "data": {
  "id": "ssm-2026-1", "manual_year": 2026, "number": 1, "title": "…", "passage": "Matthew 2:1-12",
  "memory_verse": { "text": "…", "reference": "Psalm 119:11" },
  "aim": "<rich text>", "introduction": "<rich text>",
  "outline": [{ "heading": "What is exceeding great joy", "body": "<rich text>" }],
  "applications": ["<rich text>", "…"], "conclusion": "<rich text>",
  "revision_questions": ["…"]
}}
```

#### 32. `GET /sunday-school/manuals/{year}/download` 🔒 premium
Response: `{ "data": { "manual": {…}, "lessons": [ …full lessons… ], "plan": [ … ] } }`

#### 33. `GET /sunday-school/search?q=joy`
Returns lesson summaries (as in `lessons` in #30) across all years. Match on title, passage and "lesson N". Up to 50 results, best match first.

#### 34/35. Bible
- `GET /bible/versions` → `[{ "id": "KJV", "name": "King James Version", "abbreviation": "KJV" }]`
- `GET /bible/KJV/chapters/JOS/1`
  ```json
  { "data": { "version": "KJV", "book_id": "JOS", "chapter": 1,
              "verses": [{ "number": 1, "text": "…" }] } }
  ```
- Book ids are **USFM codes** (`GEN … REV`). The canonical list is in `src/core/utils/scripture.ts`.
- `404` for an invalid book or chapter.
- Use long cache headers (`max-age=604800`); the text never changes.
- **Source of text:** import a public-domain KJV dataset into the database, or proxy a licensed Bible API. **[CONFIRM]**

#### 36/37. Events
`GET /events?year=2026` → `[ event ]`, and `GET /events/{id}` → `event`:
```json
{ "id": "ev-…", "title": "National Youth Convention 2026", "start_date": "2026-01-15",
  "end_date": "2026-01-17", "start_time": "09:00", "description": "plain text",
  "location": "Uyo, Akwa Ibom", "category": "youth", "share_url": "https://…" }
```
- Include events that **start** in the requested year.
- `start_time` is in Africa/Lagos time, or `null` for all-day events.

#### 38. `GET /branches?q=port%20harcourt&page=1&per_page=30`
Response: `{ "data": [ branch ], "meta": { "current_page": 1, "last_page": 3, "per_page": 30, "total": 74 } }`

`branch = { id, name, address, city, state, phone, latitude, longitude }`.
- Lat/lng may be `null`; the app then falls back to address search.
- Search across name, address, city and state (Postgres `ILIKE` or full-text search).
- With no `q`, sort alphabetically.

#### 39. `GET /search?q=grace` [OPTIONAL but recommended]
```json
{ "data": { "hymns": [ hymnSummary ], "devotionals": [ entrySummary ],
            "lessons": [ lessonSummary ], "events": [ event ] } }
```
Up to 10 results per group. Search devotionals in the current edition only. When offline, the app falls back to searching the cached hymn catalogue.

### C.5 Personal data (sync) 🔒 [MANDATORY]

All of these are **idempotent full-state writes**, so the app can safely retry after being offline.

| Endpoint | Request | Response |
|---|---|---|
| `GET /me/favourites` | — | `{ "data": { "hymn_ids": ["h18"], "lesson_ids": ["ssm-2026-1"] } }` |
| `PUT /me/favourites` | `{ "hymn_ids": [...], "lesson_ids": [...] }` (full replacement, ≤ 2,000 each) | `204` |
| `GET /me/programs` | — | `{ "data": [ program ] }` |
| `PUT /me/programs/{id}` | `program` (id is a client UUID v4) | `201` if created, `204` if updated |
| `DELETE /me/programs/{id}` | — | `204` (also `204` if already gone) |

`program = { "id": "uuid", "name": "Sunday Morning Service", "date": "2026-08-02" | null, "hymn_ids": ["h4","h9"], "updated_at": "ISO" }`

**Validation**
- `name`: required, ≤ 80 characters.
- `hymn_ids`: ≤ 100 entries, all existing hymn ids, order preserved.

**Merge rules (app side)**
- Favourites: union of server and local.
- Programs: last write wins, based on `updated_at`.

---

## D. Authentication and user management

### D.1 Token model [MANDATORY] [PROPOSED]
- **Access token:** short-lived, **15 min** (Sanctum personal access token with expiry, or Passport/JWT).
- **Refresh token:** long-lived (**60 days**), opaque, stored **hashed** in the database.
  - **Rotated on every refresh.** Reusing a revoked refresh token should revoke the whole token family (theft detection).
- Token response, used in several places:
  ```json
  { "access_token": "…", "refresh_token": "…", "expires_in": 900, "token_type": "Bearer" }
  ```
- The app stores both tokens in the iOS Keychain or the Android Keystore, never in plain storage.
- `device_name` (e.g. "UEC ios app") is sent on login so users can see and revoke sessions later **[OPTIONAL]**.

**Auth result** (returned by register/verify, login and Google):
```json
{ "data": { "user": { user }, "tokens": { tokens } } }
```
```json
user = { "id": "usr_…", "name": "Praise" | null, "phone": "+2348123456789" | null,
         "email": "praise@example.com" | null, "phone_verified_at": "ISO" | null,
         "email_verified_at": "ISO" | null, "avatar_url": "https://…" | null,
         "is_premium": true, "roles": ["member"], "created_at": "ISO" }
```
`is_premium` is true while the user has an active (or cancelled-but-not-expired) subscription.

**Verification challenge** (returned by every endpoint that sends an OTP):
```json
{ "data": { "verification_id": "ver_…", "channel": "phone" | "email",
            "destination_masked": "+234 ••• ••• 6789", "expires_in": 600, "resend_in": 45 } }
```

### D.2 Registration
1. `POST /auth/register`
   - Request: `{ "channel": "phone", "phone": "+2348123456789", "password": "…" }`, or `{ "channel": "email", "email": "…", "password": "…" }`
   - Response: `201` with a challenge.
   - **Validation:**
     - `phone`: E.164 Nigerian mobile (`^\+234[789][01]\d{8}$`). The app normalises before sending.
     - `email`: RFC email, lower-cased.
     - `password`: min 8, letters + digits; check against breached-password lists (`Password::uncompromised()`).
     - Unique phone or email, else `422` with `errors.phone` / `errors.email`.
   - Store the pending registration in Redis (draft + hashed OTP), **not** in the users table, until it is verified.
   - Phone OTPs go via **WhatsApp** (design: "WhatsApp number only"); fall back to SMS if delivery fails **[CONFIRM]**. Email OTPs go via **ZeptoMail**.
2. `POST /auth/register/verify`
   - Request: `{ "verification_id", "code", "device_name" }`
   - Response: `201` with the auth result.
   - Errors: `422 errors.code` (wrong code), `410 OTP_EXPIRED`, `429 OTP_LOCKED` (after 5 attempts).
3. `POST /auth/otp/resend`
   - Request: `{ "verification_id" }`
   - Response: `{ "resend_in": 45, "expires_in": 600 }`
   - Enforce the 45 s cooldown → `429`. Invalidate the previous code.

### D.3 Login
`POST /auth/login`: `{ "channel": "phone" | "email", "identifier": "+234…" | "a@b.c", "password", "device_name" }` → auth result.

- Wrong credentials → `422` with `message` and `errors.identifier` (generic: don't reveal which field was wrong).
- Unverified account → `403` with:
  ```json
  { "code": "ACCOUNT_UNVERIFIED", "message": "…", "verification": { challenge } }
  ```
  A fresh OTP has already been sent.
- Throttle: 5 attempts per minute per identifier+IP, with lockout (Laravel `RateLimiter`).

### D.4 Google sign-in
`POST /auth/google`: `{ "id_token", "device_name" }` → auth result.

- **Verify the ID token on the server** (`google/apiclient` or JWKS):
  - signature valid
  - `aud` ∈ {web client id, iOS client id, Android client id}
  - `iss` = `accounts.google.com` / `https://accounts.google.com`
  - not expired
  - `email_verified` = true
- Link by Google `sub` first, then by verified email. Create the account if neither matches.
- The app never trusts the token itself.

### D.5 Password reset
1. `POST /auth/password/forgot`: `{ "channel", "identifier" }` → challenge.
   - **Always return 200 with a challenge, even for unknown accounts.** This prevents account enumeration. Simply don't send a code if there is no account.
2. `POST /auth/password/verify`: `{ "verification_id", "code" }` → `{ "reset_token": "…" }` (single use, 15 min).
3. `POST /auth/password/reset`: `{ "reset_token", "password", "password_confirmation" }` → `204`.
   - On success, **revoke all of the user's refresh tokens** and send a "your password was changed" email/WhatsApp notice.

### D.6 Refresh & logout
- `POST /auth/refresh`: `{ "refresh_token" }` → tokens (rotated). Invalid or expired → `401 REFRESH_INVALID`.
- `POST /auth/logout` 🔒: `{ "refresh_token" }` → `204`. Revokes that session. Also remove the device's FCM token (the app calls `DELETE /me/devices/{token}` first).

### D.7 Profile endpoints 🔒
- `GET /me` → user.
- `PATCH /me`: `{ "name": "Praise A" | null }` → user. Name ≤ 80 characters.
- `POST /me/avatar`: multipart field `avatar` (jpeg/png/webp/heic, ≤ 5 MB) → user.
  - Upload to **Cloudinary** (auto-crop square, 512 px) or S3. Return a CDN URL in `avatar_url`.
  - Strip EXIF/GPS metadata.
- `POST /me/contact`: `{ "channel": "email" | "phone", "value" }` → `201` challenge. `422` if the value is already in use.
- `POST /me/contact/verify`: `{ "verification_id", "code" }` → updated user (sets `*_verified_at`).
- `DELETE /me`: body `{ "confirmation": "DELETE" }` → `204`. See B.2. Must cancel gateway subscriptions.

---

## E. Payments and subscriptions

### E.1 ⚠️ App-store policy decision [CONFIRM — blocking]
The design sells **digital subscriptions that unlock in-app content** (audio, solfa, offline downloads).
- **Apple App Store Guideline 3.1.1** requires Apple In-App Purchase for this.
- **Google Play Payments policy** requires Google Play Billing for this.
- Paying through Paystack/Monnify inside the app risks **rejection or removal**. The Nigerian reader-app and user-choice billing exceptions do not clearly apply.

| Option | Description | Effort |
|---|---|---|
| **A (recommended)** | Use StoreKit / Play Billing on mobile (e.g. RevenueCat). The backend validates store receipts and server notifications, then grants the same entitlements. Paystack/Monnify stay on the **website**. | Medium; the app's purchase layer is isolated in `src/features/subscription/purchase.ts` |
| B | Sell on the website only. The app shows no prices or purchase buttons (reader-app approach), and users log in to unlock. | Low; but it changes the design |
| C | Keep Paystack/Monnify in the app as designed. **High rejection risk.** | Already built (below) |

The app currently implements **Option C** exactly as designed, behind an abstraction, so switching to A is contained. **UEC must decide before store submission.** The rest of this section documents Option C. Entitlements (§E.6) apply to every option.

### E.2 Plans
`GET /subscription/plans`:
```json
[{ "id": "plan_monthly", "name": "Monthly", "interval": "month", "amount": 250000, "currency": "NGN", "savings_percent": null, "highlighted": false },
 { "id": "plan_six_months", "name": "6 Months", "interval": "six_months", "amount": 1200000, … },
 { "id": "plan_yearly", "name": "Yearly", "interval": "year", "amount": 1990000, "savings_percent": 34, "highlighted": true }]
```
Create matching **Paystack Plans** (`interval: monthly | biannually | annually`) and **Monnify** equivalents. Store the provider plan codes on your `plans` table.

### E.3 Checkout flow
```
App                       Laravel                         Paystack/Monnify
 │ POST /me/subscription/checkout {plan_id, provider, callback_url}
 │──────────────────────────▶│ create pending payment (reference)
 │                           │ initialize transaction ───────────────▶│
 │◀──── {reference, authorization_url}                                │
 │ open authorization_url in in-app browser ─────────────────────────▶│ user pays
 │                           │◀────── webhook charge.success (signed) │
 │                           │ verify server-to-server, activate sub  │
 │   gateway redirects browser → GET /payments/callback?reference=…   │
 │◀── 302 uecapp://payment/callback?reference=…  (closes browser)     │
 │ POST /me/subscription/verify {reference}  (polled while pending)   │
 │──────────────────────────▶│ returns status + subscription          │
```

1. `POST /me/subscription/checkout` 🔒
   - Request: `{ "plan_id": "plan_yearly", "provider": "paystack" | "monnify", "callback_url": "uecapp://payment/callback" }`
   - Response:
     ```json
     { "data": { "reference": "uec_pay_…", "provider": "paystack",
                 "authorization_url": "https://checkout.paystack.com/…", "expires_at": "ISO" | null } }
     ```
   - Server-side:
     - Generate a unique `reference` and persist `payments(status=pending, user, plan, amount, provider)`.
     - **Amount is taken from the plan in the database, never from the client.**
     - Paystack: `POST /transaction/initialize` with `email` (use a placeholder like `{user_id}@users.uec` if the user has no email), `amount`, `plan`, `reference`, `callback_url = https://<api>/payments/callback`, and `metadata.user_id`.
     - Monnify: `POST /api/v1/merchant/transactions/init-transaction` with `paymentReference` and `redirectUrl`.
     - `409 ALREADY_SUBSCRIBED` if the user already has an active subscription (use change-plan instead).
   - **The app's `callback_url` is a custom scheme.** Gateways prefer `https`, so send your own `https` callback to the gateway. That page then redirects to the app's URL.
2. `GET /payments/callback?reference=…` (browser, public)
   - Don't trust its query params for anything except the redirect.
   - Respond `302 Location: uecapp://payment/callback?reference=<ref>`, with a fallback HTML link.
3. `POST /me/subscription/verify` 🔒
   - Request: `{ "reference" }`
   - Response:
     ```json
     { "data": { "status": "success" | "pending" | "failed" | "abandoned", "subscription": { subscription } | null } }
     ```
   - If the webhook hasn't arrived yet, call the gateway's verify API (`GET /transaction/verify/{reference}`).
   - Before activating, check `amount`, `currency` and `status`, and that the reference belongs to this user.
   - **Idempotent.** The app polls this up to about 6 times over ~15 s while the status is `pending`.
   - `404` if the reference is unknown or belongs to another user.

### E.4 Webhooks [MANDATORY]
- **Paystack** `POST /webhooks/paystack`
  - Verify `x-paystack-signature` = HMAC-SHA512(raw body, secret key).
  - Only accept requests from Paystack IPs (optional).
  - Handle: `charge.success`, `subscription.create`, `subscription.disable`, `subscription.not_renew`, `invoice.create`, `invoice.payment_failed`.
- **Monnify** `POST /webhooks/monnify`
  - Verify `monnify-signature` = HMAC-SHA512(raw body, client secret).
  - Handle: `SUCCESSFUL_TRANSACTION`, `FAILED_TRANSACTION`, and recurring-payment events.
- Process **idempotently**: store `event_id`/reference and ignore duplicates. Respond `200` fast and queue the work (Redis queue).
- Webhooks are the **source of truth** for renewals and failures. `verify` is a fallback for the first purchase only.

### E.5 Subscription state 🔒
`GET /me/subscription`:
```json
{ "data": {
  "subscription": {
    "id": "sub_…", "status": "active" | "cancelled" | "past_due" | "expired",
    "plan": { plan }, "started_at": "ISO", "current_period_end": "ISO",
    "next_billing_date": "ISO" | null, "cancelled_at": "ISO" | null, "access_until": "ISO" | null,
    "payment_method": { "type": "card", "label": "Card" } | null,
    "pending_plan": { plan } | null, "pending_plan_effective_at": "ISO" | null
  } | null,
  "entitlements": ["hymn.audio", "hymn.solfa", "offline.downloads", "archive.editions"]
}}
```
- **Plan change**
  - `GET /me/subscription/change-preview?plan_id=` → `{ plan, current_plan, effective_date: "YYYY-MM-DD", amount }`.
  - `POST /me/subscription/change` `{ "plan_id" }` → subscription with `pending_plan` set.
  - The design says the **new plan starts on the next billing date**: no proration, no immediate charge.
  - At renewal: disable the old gateway subscription and create the new one, or update the plan code.
  - `409 SAME_PLAN`.
- **Cancel**
  - `POST /me/subscription/cancel` `{ "reason": string | null }` → subscription with `status: "cancelled"`, `cancelled_at`, and `access_until = current_period_end`.
  - Disable auto-renew at the gateway (Paystack `POST /subscription/disable`). Entitlements remain until `access_until`.
  - Changing plan while cancelled should re-activate auto-renew.
- `past_due`: renewal failed. Give a **[CONFIRM]** grace period (e.g. 3 days) before expiry, and notify the user (§H).

### E.6 Entitlements [MANDATORY]
- Compute entitlements on the server from the subscription.
- Return them on `/me/subscription`, reflect them in `user.is_premium`, and **enforce them on premium endpoints**:
  - downloads (#26, #32) → `403 PREMIUM_REQUIRED`
  - signed audio/solfa URLs (#22)
- Client-side gating is UX only.

### E.7 Transaction history, receipts, donations [OPTIONAL] [CONFIRM]
- The designs have **no** transaction-history or receipt screen. Still:
  - Store every payment (reference, amount, status, provider, raw gateway payload).
  - Email a receipt via **ZeptoMail** on success and on each renewal.
  - Expose `GET /me/payments` later if UEC wants a history screen.
- **Donations** happen on the website. If you also want donation records linked to app users, add `?uid=` (a signed, opaque token) to `donation_url`. **[CONFIRM]**

### E.8 Failure scenarios to support
| Scenario | Expected behaviour |
|---|---|
| User closes the browser before paying | `verify` → `abandoned`/`pending`; the app shows "not completed / still confirming" |
| Paid, but the webhook is delayed | `verify` calls the gateway API → `success` |
| Paid, then the app crashes | On next launch, `GET /me/subscription` already reflects it (webhook) |
| Duplicate webhook | Ignored by idempotency key |
| Amount mismatch / tampered reference | Reject, log a security event, keep `pending` |
| Renewal card failure | `past_due` → notification → grace → `expired` |
| Refund / chargeback | Revoke entitlements; mark the payment `refunded` |

---

## F. Third-party integrations

All secrets live **only** on the backend (`.env`, secrets manager). The app holds only public identifiers.

| Service | Purpose | Backend responsibilities | App-side config needed from you |
|---|---|---|---|
| **Paystack** | Subscriptions (and website donations) | Plans; initialize/verify; webhooks (HMAC); subscription disable; test & live keys | none (public key not needed: hosted checkout) |
| **Monnify** | Alternative gateway | Init/verify; webhooks; recurring; contract code | none |
| **Zoho ZeptoMail** | Transactional email: OTPs, receipts, password-changed, account-deleted, subscription notices | Templates (EN/Pidgin/Igbo, by `Accept-Language`), queue sending, bounce handling, SPF/DKIM/DMARC on the sending domain | — |
| **WhatsApp Business Platform** (Cloud API) | Phone OTP delivery; optional notifications | Approved **authentication template** for OTP (with copy-code button); opt-in records; SMS fallback provider **[CONFIRM]** | — |
| **Firebase** | FCM push; optional Analytics/Crashlytics | Firebase project; FCM HTTP v1 via service account; topic management; send on content publish | `google-services.json` (Android), `GoogleService-Info.plist` (iOS), APNs auth key uploaded to Firebase |
| **Google Sign-In** | Social login | Verify ID tokens (§D.4) | OAuth client IDs: **Web** (`VITE_GOOGLE_WEB_CLIENT_ID`), **iOS** (`VITE_GOOGLE_IOS_CLIENT_ID` + reversed id URL scheme), **Android** (SHA-1/SHA-256 of upload & Play signing keys registered) |
| **Cloudinary** | Avatars, images, cover art | Upload, transform (square 512 px avatar, `f_auto,q_auto`), signed uploads only from backend | — |
| **Amazon S3** | Hymn audio, edition bundles, solfa images, feedback attachments | Private buckets; **signed URLs** (≤ 15 min); CloudFront optional; lifecycle rules for attachments | — |
| **Deep links** | `uecapp://` + optional universal links | Host `/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json` on the app-link domain **[OPTIONAL]** | Domain name → `VITE_UNIVERSAL_LINK_HOST` |
| **Error monitoring** | Sentry or Crashlytics **[CONFIRM]** | — | DSN (public) |

---

## G. Database requirements (proposed)

> **[PROPOSED]** These are the mobile team's suggestions, derived from the screens. The backend team owns the final schema. Laravel conventions apply (`id`, timestamps, soft deletes where noted).

```
users              id, name?, phone? (unique, E.164), email? (unique, ci), password (bcrypt/argon2id),
                   phone_verified_at?, email_verified_at?, google_sub? (unique), avatar_url?,
                   locale (en|pcm|ig), last_login_at?, deleted_at?, timestamps
roles / role_user  (spatie/laravel-permission): member, content_editor, finance, admin, super_admin
refresh_tokens     id, user_id, family_id, token_hash, device_name, ip, user_agent,
                   expires_at, revoked_at?, replaced_by_id?, timestamps
verifications*     (Redis, TTL 10 min) id, purpose(register|reset|contact), channel, destination,
                   code_hash, attempts, user_id?, draft(json, encrypted)?
devices            id, user_id, token (unique), platform, app_version, locale, last_seen_at
hymn_categories    id, slug (unique), name, sort
hymns              id, number (unique), title, first_line, author?, composer?, tune?, meter?,
                   key?, tempo?, about?(text), scripture_refs(jsonb), tags(jsonb), published_at?
hymn_category      hymn_id, hymn_category_id
hymn_stanzas       id, hymn_id, position, type(verse|chorus|refrain|bridge), number?, lines(jsonb)
hymn_solfa         hymn_id (pk), format(text|image), key?, time_signature?, sections(jsonb)?, image_path?
hymn_audio         id, hymn_id, label, storage_path, duration_seconds, size_bytes
devotional_editions year (pk), title, theme, is_premium, published_at?, bundle_path?, bundle_size
devotional_entries id, edition_year, date (unique), title, passage, memory_verse_text,
                   memory_verse_ref, body(text), declaration?, prayer?, published_at?
reading_progress   user_id, entry_date, read_at — unique(user_id, entry_date)
ss_manuals         year (pk), title, theme, is_premium, published_at?, bundle_path?, bundle_size
ss_lessons         id, manual_year, number, title, passage, memory_verse_text, memory_verse_ref,
                   aim, introduction, outline(jsonb [{heading, body}]), applications(jsonb),
                   conclusion, revision_questions(jsonb) — unique(manual_year, number)
ss_plan_weeks      id, manual_year, date (Sunday), week, lesson_id?, description
bible_versions     id (e.g. KJV), name, abbreviation, licence_note
bible_verses       version_id, book_id (USFM), chapter, verse, text — pk(version, book, chapter, verse)
events             id, title, start_date, end_date?, start_time?, description, location?, category?,
                   share_url?, published_at?
branches           id, name, address, city?, state?, phone?, latitude?, longitude?,
                   search_vector (tsvector) — GIN index
pages              slug (pk: about|anthem|doctrine|terms|privacy), title, body, updated_by
favourites         user_id, favouritable_type (hymn|lesson), favouritable_id — unique triple
programs           id (uuid, client-generated), user_id, name, date?, hymn_ids(jsonb), updated_at
plans              id, name, interval, amount_kobo, currency, savings_percent?, highlighted,
                   paystack_plan_code?, monnify_plan_code?, active
subscriptions      id, user_id, plan_id, status, provider, provider_subscription_code?,
                   started_at, current_period_end, cancelled_at?, pending_plan_id?, timestamps
payments           id, user_id, plan_id?, reference (unique), provider, amount_kobo, currency,
                   status(pending|success|failed|abandoned|refunded), paid_at?, raw(jsonb), timestamps
webhook_events     id, provider, event_id/reference (unique), type, payload(jsonb), processed_at?
feedback           id, user_id?, type, message, contact_consent, app_version, platform, status, timestamps
feedback_files     id, feedback_id, storage_path, mime, size
notifications*     [OPTIONAL — no inbox screen designed] id, user_id?, title, body, route, sent_at
app_config         key/value or a single-row settings table (donation_url, versions, providers)
```
\* = not necessarily a SQL table.

**Relationships**
- user 1–n devices, refresh_tokens, favourites, programs, reading_progress, payments
- user 1–1 current subscription
- plan 1–n subscriptions
- edition 1–n entries
- manual 1–n lessons and plan weeks
- hymn 1–n stanzas and audio; hymn n–n categories

**Bundles.** Pre-render the edition/manual download JSON to S3 when content is published (queue job), so `/download` is cheap.

---

## H. Notifications

### H.1 Push (FCM) [MANDATORY]
- The app asks for notification permission **after sign-in**, registers with FCM, and calls `POST /me/devices`:
  ```json
  { "token": "…", "platform": "ios" | "android", "app_version": "1.0.0", "locale": "en" }
  ```
  Upsert by token, and remove tokens on `UNREGISTERED` errors from FCM.
- **Payload contract:**
  ```json
  { "notification": { "title": "Today's Daily Delight", "body": "Keys to Notable…" },
    "data": { "route": "/devotional/read/2026-01-05", "type": "devotional" } }
  ```
  `data.route` must be an in-app path. The app only opens paths starting with:
  `/hymns`, `/devotional`, `/sunday-school`, `/events`, `/bible`, `/branches`, `/subscription`, `/payment/callback`, `/tabs/home`, `/tabs/give`, `/tabs/more`, `/content/`.
- Suggested triggers **[CONFIRM]**: daily devotional reminder (topic `dde-daily`, e.g. 6 am Africa/Lagos), new event or announcement (topic `announcements`), Sunday School reminder on Saturday evening, and subscription renewal/failure (direct to user).
- Guests can't register devices today. **[OPTIONAL]** Allow anonymous topic subscription later.

### H.2 Transactional email (ZeptoMail) / WhatsApp [MANDATORY]
OTP codes · welcome · password changed · email/phone changed · subscription started, renewed, renewal failed, cancelled and expired · payment receipt · account deleted.

### H.3 In-app notifications / announcements [OPTIONAL] [CONFIRM]
No inbox or announcement screen was designed, so none is built. If wanted, we propose `GET /me/notifications?page=` and `POST /me/notifications/{id}/read`, plus a new screen (design needed).

### H.4 Notification preferences [OPTIONAL] [CONFIRM]
Not designed. Proposal: `GET/PUT /me/notification-preferences { "dde_daily": true, "announcements": true, "ssm_reminder": false }`.

---

## I. Security and access control

### I.1 Authentication & sessions
- Short-lived access tokens; rotated, hashed refresh tokens with family revocation (§D.1).
- Revoke all sessions on password reset and account deletion. **[OPTIONAL]** Let users "log out all devices".
- Throttle every OTP, login and reset endpoint per identifier and per IP. Lock out after repeated failures. Monitor OTP spend: WhatsApp and SMS cost money, and OTP pumping is a known attack.
- Hash OTP codes; compare in constant time; single use.
- Account-enumeration safe: forgot-password always returns 200; login errors are generic.

### I.2 Authorisation
- **Roles** (spatie/permission): `member` (default), `content_editor` (hymns/devotionals/lessons/events/pages), `finance` (payments, read-only), `admin`, `super_admin`. The mobile app uses only member endpoints.
- Every `/me/*` query is scoped to `auth()->id()`. Program and favourite IDs from the client must never reach another user's rows (policies + scoped queries).
- Premium enforcement happens on the server (§E.6).

### I.3 API security
- HTTPS only with HSTS; TLS 1.2+.
- **CORS:** native apps send `Origin: capacitor://localhost` (iOS) and `https://localhost` (Android); allow these plus your web origins.
- Validate every input with Form Requests. Limit request sizes: JSON ≤ 1 MB, uploads ≤ 5 MB each, ≤ 4 files.
- Check uploaded files' **MIME type by content** (not by extension). Re-encode images; scan attachments **[OPTIONAL]**.
- Verify webhook signatures and process idempotently.
- Validate `callback_url`: accept only `uecapp://payment/callback` or your own https domain (no open redirect).
- Never return secrets, internal IDs of other users, or stack traces (`APP_DEBUG=false`).
- Apply security headers on any HTML you serve (callback page): CSP, `X-Content-Type-Options`, etc.

### I.4 Data protection (NDPA 2023 — Nigeria Data Protection Act)
- Personal data held: phone, email, name, avatar, reading history, favourites, payment records, feedback.
- **[CONFIRM]** Privacy policy and terms URLs (shown on the paywall), data-retention periods, and a DPO contact.
- Encrypt at rest: database disk encryption; encrypt `draft` payloads in Redis.
- Account deletion: delete or anonymise within 30 days. Keep payment records only as long as financial law requires.
- Logs must not contain passwords, OTPs, tokens or full card data. The app redacts these on its side too.

### I.5 Mobile-side controls (for context)
- Tokens are kept in the Keychain/Keystore.
- Android backups are disabled; cleartext traffic is disabled.
- A Content-Security-Policy is applied in the webview.
- No `v-html` (no injection from content).
- Deep links are checked against an allowlist.
- Production builds refuse the mock API and non-HTTPS base URLs.

---

## J. Backend dependencies and action items

### J.1 Decisions required from UEC [CONFIRM]
| # | Decision | Blocks |
|---|---|---|
| 1 | **Payment model for subscriptions** (§E.1: IAP vs website vs in-app Paystack) | Store submission |
| 2 | Free vs premium content split (§B.11) | Entitlement rules |
| 3 | Plan prices and intervals (design values assumed) | Plans |
| 4 | Password policy (we use ≥ 8 with letters + numbers) | Validation |
| 5 | OTP channel for phone: WhatsApp only, or SMS fallback (provider?) | Sign-up |
| 6 | Bible versions & licensing; hymn text and audio copyright | Content import |
| 7 | Future-dated devotional visibility | Entries API |
| 8 | Push notification schedule and topics | FCM jobs |
| 9 | App identifiers: **bundle/application ID** (currently `org.uec.mobile`), app name, deep-link scheme (`uecapp`), universal-link domain | Native builds; **cannot change after first store upload** |
| 10 | Donation linkage & receipts (§E.7) | Website |
| 11 | Notification inbox / preferences screens (not designed) | Scope |
| 12 | Legacy brief modules (sermons, ministries, registrations) | Scope |

### J.2 Credentials & configuration the backend must provision
- Paystack test + live secret keys; webhook URL registered.
- Monnify API key, secret key, contract code (test + live); webhook URL registered.
- ZeptoMail API token + verified sending domain (SPF/DKIM/DMARC).
- WhatsApp Business: Meta app, WABA id, phone number id, permanent token, approved OTP template.
- Firebase project: service account for FCM v1; APNs key uploaded; Android & iOS apps registered. Send `google-services.json` and `GoogleService-Info.plist` to the mobile team (not committed to git).
- Google Cloud OAuth clients (web, iOS, Android with SHA fingerprints).
- Cloudinary cloud name + API key/secret (backend only).
- AWS S3 buckets (private) + IAM role with least privilege.
- Production domain + TLS; staging environment with test keys.

### J.3 Backend build order (suggested)
1. Conventions, error format, auth (register/verify/login/refresh/logout/reset), `/me`, devices.
2. Content read APIs: config, pages, hymns, devotionals, Sunday School, events, branches, Bible import.
3. Personal sync: favourites, programs, progress.
4. Feedback (S3 attachments).
5. Subscriptions per the decision in J.1 #1 (+ webhooks, entitlements, bundles, signed media URLs).
6. Push triggers & transactional notifications.
7. Admin/CMS for content editors (Filament/Nova recommended).

### J.4 What the mobile team will do once endpoints exist
- Run the app with `VITE_API_MODE=live` against staging; fix any contract errors.
- Replace the mock gateway with real hosted checkout (or IAP, per decision).
- Drop in Firebase configs and Google client IDs; test push and sign-in on devices.

---

## K. Integration and testing checklist

### K.1 API readiness
- [ ] All responses use `{ data }` and snake_case; dates `YYYY-MM-DD`, timestamps ISO-8601 UTC, money in kobo
- [ ] 422 errors use Laravel's `errors` bag; `code` values from §C.1 used where listed
- [ ] Every endpoint in §C.3 returns data that passes the app (run `npm run dev:live`, check the console for `API contract mismatch`)
- [ ] `Accept-Language` respected for any server-generated messages
- [ ] Pagination meta present on `/branches`
- [ ] CORS allows `capacitor://localhost` and `https://localhost`
- [ ] Cache headers / ETags on catalogue, Bible, pages

### K.2 Authentication
- [ ] Phone sign-up → WhatsApp OTP arrives → verify → logged in
- [ ] Email sign-up → ZeptoMail OTP → verify → logged in
- [ ] Wrong OTP ×5 → locked; expired OTP → 410; resend respects 45 s
- [ ] Login with phone and with email; wrong password → generic 422
- [ ] Unverified account login → 403 `ACCOUNT_UNVERIFIED` + challenge
- [ ] Google sign-in on Android & iOS (real devices); ID token verified on the server
- [ ] Access token expiry → app refreshes transparently; reused refresh token → 401 and family revoked
- [ ] Forgot password for existing & non-existing accounts (both 200); reset revokes sessions
- [ ] Add email / phone from Profile with OTP; avatar upload
- [ ] Delete account removes access, cancels subscription, revokes tokens

### K.3 Content & offline
- [ ] Hymn catalogue, detail, categories; audio signed URLs play on device
- [ ] Devotional today / month / any date; 404 for missing dates
- [ ] Sunday School manual, plan, lesson, search
- [ ] Bible chapter fetch; references like `Psalm 16:11; James 1:2-4` resolve
- [ ] Premium download of DDE edition & SSM manual; app reads them in airplane mode
- [ ] Favourites, programs and reading progress sync between two devices on the same account

### K.4 Payments (sandbox)
- [ ] Checkout → Paystack test card success → webhook → `verify` success → entitlements present
- [ ] Same with Monnify
- [ ] Abandoned checkout → `abandoned`/`pending`, no entitlement
- [ ] Duplicate webhook ignored; tampered amount rejected
- [ ] Plan change preview & scheduled change; renewal applies new plan
- [ ] Cancel → access until period end → expires
- [ ] Renewal failure → `past_due` → notification → expiry after grace
- [ ] Receipts emailed

### K.5 Notifications
- [ ] Device token registered after login; removed on logout
- [ ] Push with `data.route` opens the correct screen (cold start & background) on both platforms
- [ ] Topic sends (daily devotional, announcements)

### K.6 Production deployment
- [ ] HTTPS + HSTS, `APP_DEBUG=false`, queues & scheduler running (Horizon), Redis persistence
- [ ] Live gateway keys, webhooks registered to production URLs
- [ ] Firebase production configs delivered; APNs production key
- [ ] Universal links files hosted (if enabled)
- [ ] Backups, monitoring/alerting, log redaction verified
- [ ] Privacy policy & terms live (URLs given to the mobile team)
- [ ] Load test: catalogue, today's devotional at 6 am peak, webhook bursts

---

*Questions about this contract: see `src/features/*/api.ts` (schemas) and `src/mocks/handlers/*.ts` (reference behaviour), or contact the mobile team.*
