<?php

/*
|--------------------------------------------------------------------------
| UEC platform configuration
|--------------------------------------------------------------------------
|
| Business rules from docs/backend-handoff.md. Values marked [CONFIRM] are
| pending UEC decisions and are deliberately configurable.
|
*/

return [

    /*
    | Which HTTP surface this deployment serves:
    |   api   -> https://api.<domain>   (mobile REST API, webhooks, payment callback)
    |   admin -> https://app.<domain>   (Livewire administration dashboard)
    |   all   -> both (local development, single-server installs)
    */
    'surface' => env('UEC_SURFACE', 'all'),

    'urls' => [
        'api' => env('UEC_API_URL', env('APP_URL')),
        'admin' => env('UEC_ADMIN_URL', env('APP_URL')),
    ],

    'timezone' => env('UEC_CONTENT_TIMEZONE', 'Africa/Lagos'),

    'locales' => ['en', 'pcm', 'ig'],

    'auth' => [
        'access_token_ttl_minutes' => (int) env('UEC_ACCESS_TOKEN_TTL', 15),
        'refresh_token_ttl_days' => (int) env('UEC_REFRESH_TOKEN_TTL_DAYS', 60),
        'reset_token_ttl_minutes' => 15,
        'check_breached_passwords' => (bool) env('UEC_CHECK_BREACHED_PASSWORDS', true),
        'login_max_attempts' => 5,
        'login_decay_seconds' => 60,
        // Nigerian mobile in E.164 (backend-handoff §D.2)
        'phone_regex' => '/^\+234[789][01]\d{8}$/',
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 600,
        'resend_cooldown_seconds' => 45,
        'max_attempts' => 5,
        'max_resends' => 5,
        // Per-destination and per-IP sending caps (OTP pumping protection, §I.1)
        'max_per_destination_per_hour' => (int) env('UEC_OTP_MAX_PER_DESTINATION_HOUR', 6),
        'max_per_ip_per_hour' => (int) env('UEC_OTP_MAX_PER_IP_HOUR', 30),
        // [CONFIRM] J.1 #5 — SMS fallback provider not chosen; WhatsApp only.
        'phone_channel' => env('UEC_OTP_PHONE_CHANNEL', 'whatsapp'),
    ],

    'content' => [
        // [CONFIRM] J.1 #7 — may users read future-dated devotionals?
        'devotional_future_visibility' => (bool) env('UEC_DEVOTIONAL_FUTURE_VISIBLE', false),
        // Offline bundles include the whole edition so premium readers can read day by day offline.
        'bundle_includes_future' => (bool) env('UEC_BUNDLE_INCLUDES_FUTURE', true),
        // [CONFIRM] §C.3 footnote — allow free (is_premium=false) editions to download without entitlement.
        'free_downloads_without_entitlement' => (bool) env('UEC_FREE_DOWNLOADS_WITHOUT_ENTITLEMENT', false),
        'pages' => ['about', 'anthem', 'doctrine', 'terms', 'privacy'],
        'hymn_categories' => [
            'praise' => 'Praise', 'prayer' => 'Prayer', 'communion' => 'Communion',
            'christmas' => 'Christmas', 'easter' => 'Easter', 'funeral' => 'Funeral',
            'thanksgiving' => 'Thanksgiving', 'morning' => 'Morning', 'evening' => 'Evening',
        ],
    ],

    'subscriptions' => [
        // [CONFIRM] §B.11 — entitlement keys granted by an active subscription.
        'entitlements' => ['hymn.audio', 'hymn.solfa', 'offline.downloads', 'archive.editions'],
        // [CONFIRM] §E.5 — grace period after a failed renewal.
        'grace_days' => (int) env('UEC_SUBSCRIPTION_GRACE_DAYS', 3),
        // How long we wait for a renewal webhook after period end before marking past_due.
        'renewal_tolerance_hours' => 24,
        'payment_reference_prefix' => 'uec_pay_',
        'checkout_expiry_minutes' => 60,
    ],

    'payments' => [
        'app_callback_url' => env('UEC_APP_PAYMENT_CALLBACK', 'uecapp://payment/callback'),
        // https hosts accepted as callback_url in addition to the app scheme (no open redirects).
        'allowed_callback_hosts' => array_filter(explode(',', (string) env('UEC_ALLOWED_CALLBACK_HOSTS', ''))),
        'placeholder_email_domain' => env('UEC_PLACEHOLDER_EMAIL_DOMAIN', 'users.uec'),
    ],

    'media' => [
        // Disk for private assets (hymn audio, solfa scans, feedback attachments, bundles).
        'private_disk' => env('UEC_PRIVATE_DISK', 'local'),
        // Disk for public images when Cloudinary is not configured.
        'public_disk' => env('UEC_PUBLIC_DISK', 'public'),
        'signed_url_ttl_minutes' => 15,
        'avatar_size' => 512,
        'max_upload_kb' => 5120,
    ],

    'push' => [
        // In-app routes the mobile app accepts in data.route (backend-handoff §H.1)
        'allowed_route_prefixes' => [
            '/hymns', '/devotional', '/sunday-school', '/events', '/bible', '/branches',
            '/subscription', '/payment/callback', '/tabs/home', '/tabs/give', '/tabs/more', '/content/',
        ],
        'batch_size' => 500,
        'concurrency' => 20,
    ],

    'feedback' => [
        'types' => ['suggestion', 'bug', 'content', 'payment', 'account', 'other'],
        'max_files' => 4,
        'max_file_kb' => 5120,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'],
    ],

    'admin' => [
        'roles' => ['content_editor', 'finance', 'admin', 'super_admin'],
    ],
];
