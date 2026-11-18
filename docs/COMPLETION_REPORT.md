# UEC Mobile — Development Completion Report

**Date:** 2026-11-13 · **Version:** 1.0.0 (pre-release) · **Readers:** UEC project lead, product owner, engineering leads

## 1. Summary

The Ionic Vue + Capacitor app is **feature-complete against the supplied designs** and runs end-to-end on a built-in mock API that implements the proposed backend contract.

- Every designed screen and user journey is implemented and functional.
- Android and iOS native projects are configured.
- The full quality pipeline passes: strict type-check, zero-warning lint, 81 unit/component/contract tests, 3 E2E journeys in a real browser, and a production web build.

It is **not yet release-ready**. Release depends on:

1. The Laravel backend implementing [`backend-handoff.md`](backend-handoff.md).
2. A **payment-model decision** (store billing policy, §5.1).
3. Real content and brand assets.
4. Third-party credentials (Firebase, Google, gateways).
5. Device QA on physical phones.

---

## 2. Verification performed

| Check | Result |
|---|---|
| `vue-tsc` strict type-check | ✅ 0 errors |
| ESLint (`--max-warnings=0`) | ✅ 0 errors / 0 warnings |
| Vitest | ✅ **81 / 81 passed** (unit, component, API-contract integration) |
| Playwright E2E (Chrome, 390×844) | ✅ **3 / 3 journeys passed** |
| Production web build (`VITE_API_MODE=live`) | ✅ Builds; mock code confirmed absent from bundle |
| Visual comparison vs. designs | ✅ Screenshots reviewed for welcome, home, hymnal, hymn detail, paywall, sign-up, devotional reader/home, SSM, events, display (dark). Layout bugs found and fixed |
| Android native debug build (`assembleDebug`) | ✅ `app-debug.apk` (17 MB) built with Gradle, SDK 36, JDK 21 |
| iOS native build | ⏳ Not possible on Windows; needs macOS/Xcode |
| Physical-device testing | ⏳ Not performed |

### 2.1 Android build
The Android project was generated, configured and built with Gradle on this machine (Android Studio's JDK 21, compile/target SDK 36, min SDK 24), producing a working debug APK. It has not yet been installed on a device or emulator. Release AAB signing and R8 minification are configured, but a signed `bundleRelease` needs UEC's upload keystore.

### 2.2 Defects found by testing and fixed
- Dates rendered as "1 January" (en-NG) instead of the design's "January 1 / Wednesday, January 1, 2025". The locale was switched to US-style English.
- Header title and subtitle rendered inline and top-aligned. Fixed to stack and centre as designed.
- The Welcome screen hero sat at the top instead of just above the buttons. Fixed the viewport height chain.
- The paywall opened full-height instead of as an auto-height sheet. Fixed.
- The bundle grouped all of Ionic into one forced chunk. Removed the manual chunking.

---

## 3. Implemented features

Legend: ✅ working on mock API (switches to live via env) · 🟡 working, depends on external setup.

| Module | Screens / capabilities | Status |
|---|---|---|
| **Launch** | Native splash; animated brand splash; Welcome (Get Started / Login / Continue as Guest) | ✅ |
| **Auth** | Create account (Phone/Email); Login; 6-digit OTP with resend timer and SMS autofill; Forgot password → OTP → new password → success; unverified-account handling; session expiry → re-login | ✅ |
| | Google Sign-In | 🟡 Needs OAuth client IDs (simulated in mock mode) |
| **Session & security** | Keychain/Keystore token storage; single-flight refresh; guest mode; logout clears account data from the device | ✅ |
| **Home** | Time-based greeting with name; global search; resource grid; Calendar card; Login pill or avatar; header elevation on scroll | ✅ |
| **Global search** | Hymns, devotionals, lessons, events; offline fallback to the hymn catalogue | ✅ |
| **Hymnal** | All Hymns (incremental list); category filter sheet; collapsing Filter FAB; offline search by number/title/first line; Favourites (+ empty state) | ✅ |
| | Hymn detail: gradient header, Lyrics / Solfa / Details, favourite toggle | ✅ |
| | Audio player: seek, prev/next within queue, speed, repeat, track choice, offline download; auto-advance | ✅ (premium) |
| | Programs: list, empty state, two-step create sheet, detail, add/remove hymns, drag-to-reorder + Move ↑/↓ + Save order, rename / change date / delete | ✅ |
| **Daily Delight** | Home (today's teaser, this month's earlier readings with unread dots, "New year, fresh start" state) | ✅ |
| | Date sheet (Day/Month/Year with unread dots) | ✅ |
| | Reader: passage & memory-verse chips, inline scripture links → Bible quick-peek, declaration, prayer, text-size slider, focus mode; auto-marks read | ✅ |
| | Library (Downloaded / Available with badges, download/delete); edition months (read/total counters); month list (unread filter) | ✅ |
| **Sunday School** | Home (this week's lesson by plan + lesson list); ⋮ menu; lesson detail (all sections, favourite, text size, focus mode, quick-peek) | ✅ |
| | Favourites; Lesson plan with month chips; Library; manual lesson list; search across years (offline fallback) | ✅ |
| **Bible** | Chapter reader; floating navigator; book picker (OT/NT chips) → chapter grid; version picker; remembers last position; chapters cached offline | ✅ |
| **Events** | Month calendar with event dots, day filter, "Events in …"; All Events with month chips; search; detail with share and Add to Calendar | ✅ |
| **Branches** | Search with infinite pagination; detail sheet with OpenStreetMap embed (no API key), call, Get Directions, View on Maps | ✅ |
| **Give** | Give Generously → external donation page in the in-app browser (URL from backend config) | ✅ |
| **More** | All rows; log in/out; version label | ✅ |
| **Content** | About / Anthem / Doctrine from CMS with offline cache (doctrine text from the design) | ✅ |
| **Display** | Font size (all reading surfaces) and Light / Dark / System theme | ✅ |
| **Language** | English / Pidgin / Igbo with English fallback | 🟡 Pidgin & Igbo are drafts |
| **Downloads** | Audio / Daily Delight / Sunday School tabs, sizes, delete | ✅ |
| **Subscription** | Paywall; Manage Subscription (active/cancelled/free); Change plan → preview sheet → success; Cancel sheet → success; payment callback deep link; entitlement gating | ✅ (mock gateway) |
| | Real payments | 🟡 See §5.1 |
| **Profile** | Avatar (camera/gallery) upload; name; add/change email or phone with OTP; Delete account flow | ✅ |
| **Feedback** | Type, message, up to 4 attachments (≤ 5 MB), contact consent → success | ✅ |
| **Platform** | Offline banner + reconnect refetch; pull-to-refresh; skeletons, empty and error states throughout; haptics on toasts | ✅ |
| | Push registration and tap routing | 🟡 Needs Firebase configs |
| | Deep links (`uecapp://`) with allow-list | ✅ |

---

## 4. Design inventory & documented assumptions

### 4.1 Design issues found
| Issue in `/docs/UEC-Designs` | Resolution |
|---|---|
| File names don't match content (e.g. "Login - Phone.png" is the OTP screen) | Mapped by content |
| **No Login screen** | Mirrors "Create account" with "Welcome back / Please sign in" and a "Forgot password?" link |
| "Create account" subtitle reads "Please sign in" | Changed to "Sign up to get started" |
| OTP copy says **6-digit** but frames show **4 boxes** | 6 digits (`OTP_LENGTH`) |
| Password placeholder "Enter 6 digit password" | ≥ 8 characters with letters + numbers (NIST/OWASP); configurable in `src/config/constants.ts` · **confirm** |
| "Programs empty state.png" shows a populated list | Built an empty state in the same style |
| ★★ icons and "Page Title" in program sheets | Treated as Figma placeholders and omitted |
| No **Solfa tab** design | Text solfa (per voice part, monospace) or scanned image; premium-gated |
| No chapter-picker design for the Bible | Chapter grid after book selection, same chip style |
| No **About UEC** / **UEC Anthem** designs or content | Generic content page; text from CMS (placeholder in mock) |
| No Programs ⋮-menu design | Action sheet: Rename / Change date / Delete |
| Home "National Calendar of Events" subtitle reads "Read Scripture" | Changed to "View church programmes" |
| SSM "Lesson Plan - All" shows a bottom tab bar; other SSM frames use a ⋮ menu | Used the ⋮ menu everywhere |
| Hymn numbers inconsistent across frames (e.g. "Glory Be…" is 9, 12 and 23) | Consistent sample numbering in mock data |
| Month counters (31/31, gold 26/31, ✓) and gold dots not explained | Counter = readings read / published (gold = partly read, ✓ = complete); gold dot = unread · **confirm** |
| Memory verse referenced as "Gen. 2:25" for a Psalm 34:15 text | Content issue; mock uses placeholders |
| No dark-mode designs | Dark palette derived from the same tokens |
| No designs for: transaction history, notification inbox or preferences, sermons/ministries/registrations (from the brief) | Not built; listed in §6 |

### 4.2 Design-fidelity notes
- Colours, type and spacing were sampled from the PNGs into `src/theme/tokens.css`.
- Lato and Lora fonts are bundled.
- Icons use **Ionicons**, the closest available set. They are not pixel-identical to the Material-style glyphs in the designs; supply an SVG icon set to match exactly.
- **Image assets are low-resolution.** The designs are 1× exports:
  - The logo is 90–150 px.
  - The cover art is about 168 px wide.
  - The splash/welcome photo is 390 px wide. It also had the baked-in logo and status bar digitally removed, which leaves a soft area behind the crest.
  - They look acceptable but soft on 3× screens. **Request original artwork:** vector logo, 1024 px icon, original building photo, cover art and illustrations.

---

## 5. Risks & decisions required

### 5.1 ⚠️ Subscription billing vs. store policy (blocking for release)
The designed in-app subscription unlocks digital content (audio, solfa, offline downloads) and is paid through Paystack/Monnify. **Apple 3.1.1 and Google Play's payments policy require their own billing for this.** Submitting as-is risks rejection or removal. The options (IAP via RevenueCat, website-only purchase, or keep as-is) are in [`backend-handoff.md` §E.1](backend-handoff.md#e1-️-app-store-policy-decision-confirm--blocking). The purchase flow is isolated in `src/features/subscription/purchase.ts`, so switching is contained. *Giving* via the external website is fine.

### 5.2 Other decisions
- Free vs. premium split.
- Plan prices.
- OTP channel (WhatsApp ± SMS).
- Bible versions and hymn/audio licensing.
- Push schedule.
- Final **bundle ID** (`org.uec.mobile` is a placeholder). This **cannot change after the first store upload**.
- Universal-link domain.
- Privacy policy and terms URLs (NDPA compliance).

The full list is in [`backend-handoff.md` §J.1](backend-handoff.md#j-backend-dependencies-and-action-items).

---

## 6. Outstanding work

| Item | Owner | Notes |
|---|---|---|
| Laravel API per handoff | Backend | The mock + contract tests define "done" |
| Switch to `VITE_API_MODE=live`; fix any contract mismatches | Mobile | Contract errors are logged automatically |
| Payment model per §5.1; real gateway (or IAP) testing in sandbox | UEC + Backend + Mobile | |
| Firebase: `google-services.json`, `GoogleService-Info.plist`, APNs key | UEC/Backend | Then verify push on devices |
| Google Sign-In client IDs + reversed iOS URL scheme + Android SHA fingerprints | UEC/Backend | |
| Real content: hymns (lyrics/solfa/audio), devotionals, lessons, events, branches (with coordinates), About/Anthem | UEC content team | Mock data is labelled sample text |
| Original brand assets → `resources/` → `npm run assets:generate` | Designer | |
| Pidgin & Igbo translations completed and reviewed by native speakers | UEC | Keys in `src/core/i18n/locales/` |
| iOS: build on macOS; add `PrivacyInfo.xcprivacy` to the target; signing; TestFlight | Mobile (macOS) | |
| Android: upload keystore; Play App Signing; internal test track | Mobile + UEC | |
| Physical-device QA (small/large phones, Android 8–15, iOS 16–18, slow network, offline) | QA | |
| Error monitoring (Sentry or Crashlytics) | Mobile | Logger seam ready (README) |
| Accessibility pass with TalkBack/VoiceOver | QA | Labels, roles and focus are in place; needs device verification |
| Optional / not designed: notification inbox & preferences, transaction history/receipts, force-update screen, sermons/ministries | Product | Need designs |

---

## 7. Known limitations

- **Mock content:** only design-provided text is real (hymn 23, the January titles, the Jan 1 reading, Lesson 1 in condensed form, the 2026 lesson plan, the doctrine). Bible verses in mock mode are placeholders.
- **Mock audio** is a synthesised tone. Mock payments use an in-app Succeed/Fail dialog.
- **Web build** is for development only. Tokens fall back to `sessionStorage` there; native builds use the Keychain/Keystore.
- **App size:** the entry chunk is about 250 kB gzip (Ionic core). It loads from local disk on device, and every screen is route-split.
- **Branch map:** uses the OpenStreetMap embed, which needs a network connection. "Get Directions" hands off to Google/Apple Maps.
- **Audio offline download** uses `Filesystem.downloadFile`. On web it depends on CORS from the media host.

---

## 8. Deployment readiness

| Area | Ready? |
|---|---|
| Codebase quality (types, lint, tests, structure) | ✅ |
| Feature completeness vs. designs | ✅ |
| Backend integration | ⏳ Waiting on API (contract delivered) |
| Payments | ⛔ Decision required (§5.1) |
| Push / Google Sign-In | ⏳ Waiting on credentials |
| Content & brand assets | ⏳ Waiting on UEC |
| Android release build | 🟡 Configured; needs keystore + Firebase file |
| iOS release build | 🟡 Configured; needs macOS/Xcode, signing, Firebase file |
| Store listings, privacy labels, policy URLs | ⏳ UEC |

**Next steps:** confirm the decisions in §5 → backend builds the auth & content endpoints first → mobile switches to live mode on staging → device QA → payments → store submission.

---

## Addendum (2026-11-14): Backend status

The Laravel API and administration dashboard described in `backend-handoff.md` have been built in this repository:

- Every endpoint (#1–#52, W1–W3) is implemented and covered by 103 automated tests on PostgreSQL.
- The custom Livewire/TypeScript admin dashboard is in place.
- Deployment, CI/CD and documentation are written.

For the mobile team:

- **Read [`FRONTEND_API_INTEGRATION_HANDOFF.md`](FRONTEND_API_INTEGRATION_HANDOFF.md) §0 first.** It lists the few places where the live API differs from or extends the proposal: ULID ids, the wrapped resend response, locked audio/solfa, a possibly empty `payment_providers`, and a nullable `share_url`.
- Backend delivery status, pending credentials and open decisions are in [`BACKEND_COMPLETION_REPORT.md`](BACKEND_COMPLETION_REPORT.md).
- The "Laravel API per handoff" row in §6 above is now done on the backend side. The next step is the mobile switch to `VITE_API_MODE=live` against staging.
