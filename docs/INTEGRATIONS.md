# UEC Backend — Third-Party Integrations

**Audience:** the engineer provisioning credentials and the operations owner.

Every integration:
- reads credentials from the environment only;
- uses a thin client with timeouts, bounded retries on connection errors and 5xx, and a row in `integration_logs` per call (no bodies, no secrets);
- can be checked and live-tested from **Dashboard → Integrations**;
- degrades gracefully: an unconfigured provider is hidden from the app (payments) or skipped (WhatsApp notices, push), and is never reported as working.

| Integration | Status in this delivery | Verified by |
|---|---|---|
| Paystack | Implemented; **awaiting live/test keys** | Automated tests against faked Paystack API (initialize, verify, webhooks, subscriptions, refunds, plan sync) |
| Monnify | Implemented; **awaiting keys** | Automated tests against faked Monnify API (auth, init, query, card-token renewals, webhooks) |
| Zoho ZeptoMail | Implemented as a Laravel mail transport; **awaiting token + verified domain** | Automated test of the HTTP payload; local pipeline verified end-to-end with the log mailer |
| WhatsApp Business (Cloud API) | Implemented; **awaiting WABA, phone number ID, token, approved templates** | Automated test of the authentication-template payload |
| Firebase Cloud Messaging v1 | Implemented; **awaiting service account + project** | Automated tests (OAuth JWT grant, per-token send, invalid-token pruning, campaign counts) |
| Google Sign-In | Implemented; **awaiting OAuth client IDs** | Automated tests with locally generated RSA keys and a faked JWKS endpoint |
| Cloudinary | Implemented; **awaiting cloud name + key/secret** | Automated test of the upload signature and delivery URL |
| Amazon S3 | Implemented via Laravel's S3 disk; **awaiting bucket + IAM keys** | Disk abstraction tested with fakes; live test on the Integrations page |
| Redis | Configured and **verified locally** (cache, queue, OTP pipeline) | End-to-end registration through the Redis queue |

> No integration has been tested against the live vendor yet: no credentials were provided. Once keys are in place, run each live test on the Integrations page and the sandbox checklist in [FRONTEND_API_INTEGRATION_HANDOFF.md §H](FRONTEND_API_INTEGRATION_HANDOFF.md#h-feature-integration-checklist).

---

## Paystack
1. Dashboard → Settings → API Keys: copy the **secret key** → `PAYSTACK_SECRET_KEY` (test key on staging, live key on production).
2. Settings → API Keys & Webhooks → **Webhook URL**: `https://api.<domain>/api/v1/webhooks/paystack`.
3. (Optional) `PAYSTACK_WEBHOOK_IPS` with Paystack's published IPs for an extra allow-list.
4. **Plans:** Dashboard → Plans → "Paystack" on each plan creates the Paystack plan and stores its code. With a plan code, Paystack runs the renewal schedule (`charge.success` webhooks). Without one, our scheduler charges the saved authorization (`/transaction/charge_authorization`).
5. Events handled: `charge.success`, `subscription.create`, `subscription.disable`, `subscription.not_renew`, `invoice.payment_failed`, `refund.processed` (others are recorded and ignored).
6. **Test:** Integrations → Paystack → Test connection (lists one transaction). Sandbox card: Paystack test card `4084 0840 8408 4081`.

## Monnify
1. Monnify dashboard → Developer: **API key**, **secret key**, **contract code** → `MONNIFY_API_KEY`, `MONNIFY_SECRET_KEY`, `MONNIFY_CONTRACT_CODE`.
2. `MONNIFY_BASE_URL`: `https://sandbox.monnify.com` (staging) / `https://api.monnify.com` (production).
3. Webhook URL: `https://api.<domain>/api/v1/webhooks/monnify` (transaction completion + refund).
4. Checkouts are **card-only**, so the reusable card token can be charged for renewals (`/api/v1/merchant/cards/charge-card-token`). Card tokenization must be enabled on the Monnify contract. **[CONFIRM with Monnify]**
5. Amounts are sent in naira (Monnify API); everything internal stays in kobo.

## Zoho ZeptoMail
1. Add and verify the sending domain (SPF, DKIM, DMARC) in ZeptoMail.
2. Create a Mail Agent → SMTP/API → copy the **Send Mail token** → `ZEPTOMAIL_TOKEN` (without the `Zoho-enczapikey` prefix).
3. `MAIL_MAILER=zeptomail`, `MAIL_FROM_ADDRESS` on the verified domain, `ZEPTOMAIL_HOST` for your data centre (`api.zeptomail.com`, `.eu`, `.in`).
4. Optional `ZEPTOMAIL_BOUNCE_ADDRESS` (configured in ZeptoMail).
5. Emails: OTP codes, welcome, password changed, email/phone changed, account deleted, payment receipts, subscription started/renewed/plan changed/renewal failed/cancelled/expired/reactivated.
6. **Test:** Integrations → ZeptoMail → Send test email.

## WhatsApp Business Platform
1. Meta Business → WhatsApp → create the app and add the phone number. Copy the **phone number ID** and **WABA ID**. Create a **system-user permanent token** with `whatsapp_business_messaging`.
2. Create and get approval for an **Authentication** template (copy-code button), e.g. `uec_otp`, language `en`. Set `WHATSAPP_OTP_TEMPLATE`.
3. Optional utility templates for notices, mapped by type in `WHATSAPP_TEMPLATES`, e.g. `password_changed:uec_password_changed,payment_receipt:uec_receipt,subscription_renewal_failed:uec_renewal_failed`. Each template's body variable `{{1}}` receives the member's name or the plan/amount (see `app/Notifications`). Notices without a configured template are sent by email only.
4. SMS fallback for undelivered WhatsApp OTPs is **[CONFIRM J.1 #5]** — no SMS provider has been chosen. In local/testing, an unconfigured WhatsApp skips delivery and logs it; in other environments the job fails loudly so the issue is visible.

## Firebase Cloud Messaging (HTTP v1)
1. Firebase console → Project settings → Service accounts → generate a key (JSON). Store it outside the web root and set `FIREBASE_CREDENTIALS=/etc/uec/firebase.json`, or base64-encode it into `FIREBASE_CREDENTIALS_BASE64`. Set `FIREBASE_PROJECT_ID`.
2. Register the Android and iOS apps in the same project. Upload the **APNs auth key**. Send `google-services.json` and `GoogleService-Info.plist` to the mobile team (not committed to git).
3. The backend sends to device tokens (registered via `POST /me/devices`) and removes tokens FCM reports as `UNREGISTERED`/invalid. Payload: `notification{title,body}` + `data{route,type}`.
4. **Test:** Integrations → Firebase → Test connection (obtains an OAuth access token). Then send a campaign to your own account from Push notifications.

## Google Sign-In
1. Google Cloud console → OAuth consent screen → Credentials: create **Web**, **iOS** and **Android** OAuth clients. The Android client needs the SHA-1/SHA-256 of both the upload key and the Play app-signing key.
2. Set `GOOGLE_WEB_CLIENT_ID`, `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_ANDROID_CLIENT_ID`; any of them is accepted as the token audience.
3. Give the mobile team the client IDs and the reversed iOS client ID URL scheme.

## Cloudinary
1. Cloudinary console → API Keys: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`; optional `CLOUDINARY_FOLDER`.
2. Avatars are uploaded with a signed request and an incoming transformation `c_fill,g_auto,w_512,h_512` (stored already cropped, without original metadata), and delivered with `f_auto,q_auto`.
3. Without Cloudinary, avatars are re-encoded locally and stored on the public disk (graceful fallback; HEIC then needs Cloudinary).

## Amazon S3
1. Create a **private** bucket (block all public access), versioning on, default encryption (SSE-S3), and a lifecycle rule that expires `healthchecks/` after 1 day.
2. Create an IAM user or role limited to that bucket: `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket`.
3. Set `UEC_PRIVATE_DISK=s3` and the `AWS_*` variables. Hymn audio, solfa scans and feedback attachments then live in S3 and are served through ~15-minute signed URLs.
4. (Optional) CloudFront in front of the bucket with signed URLs.

## Redis
- Used for cache (catalogue, bundles, Bible chapters, settings), queues (`otp`, `webhooks`, `notifications`, `default`), admin sessions, OTP challenges, rate limiters and scheduler locks.
- Production: Redis 7 with `appendonly yes` (AOF persistence) so pending OTPs and queued jobs survive restarts; `maxmemory-policy noeviction` for the queue/session database.

## Bible text
KJV is public domain; other versions need a licence (**[CONFIRM J.1 #6]**). Import on the API host:
```bash
php artisan uec:bible:import KJV /path/to/kjv.json --name="King James Version" --licence="Public domain"
```
Accepted formats: JSON rows `{"book":"GEN","chapter":1,"verse":1,"text":"…"}`, the common 66-book nested JSON layout (`[{ "chapters": [["v1","v2"],…] }, …]` in canonical order), or CSV `book,chapter,verse,text`. Rows are validated (USFM book, chapter range, non-empty text) before anything is written, and the import is idempotent (upsert). Then enable or hide versions under **Dashboard → Bible**.
