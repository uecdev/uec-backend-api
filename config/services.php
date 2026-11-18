<?php

/*
|--------------------------------------------------------------------------
| Third-party services
|--------------------------------------------------------------------------
|
| Every credential is read from the environment. Nothing secret is stored
| in the database or committed to source control. An integration counts as
| "configured" only when its required keys are present (see
| App\Integrations\IntegrationRegistry).
|
*/

return [

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Optional IP allow-list for webhooks (comma separated). Empty = signature check only.
        'webhook_ips' => array_filter(explode(',', (string) env('PAYSTACK_WEBHOOK_IPS', ''))),
        'timeout' => 20,
    ],

    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY'),
        'secret_key' => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
        'timeout' => 20,
    ],

    'zeptomail' => [
        'token' => env('ZEPTOMAIL_TOKEN'),
        // api.zeptomail.com | api.zeptomail.eu | api.zeptomail.in
        'host' => env('ZEPTOMAIL_HOST', 'api.zeptomail.com'),
        'bounce_address' => env('ZEPTOMAIL_BOUNCE_ADDRESS'),
        'timeout' => 15,
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE', 'uec_otp'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        // Optional approved utility templates for transactional notices, by notification type.
        // Format: type:template_name,type:template_name  (e.g. password_changed:uec_password_changed)
        'templates' => collect(explode(',', (string) env('WHATSAPP_TEMPLATES', '')))
            ->filter()
            ->mapWithKeys(function (string $pair) {
                [$type, $name] = array_pad(explode(':', $pair, 2), 2, null);

                return $name ? [trim($type) => trim($name)] : [];
            })->all(),
        'timeout' => 15,
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        // Path to the service-account JSON, or the JSON itself base64-encoded.
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
        'timeout' => 10,
    ],

    'google' => [
        // OAuth client IDs whose ID tokens we accept (aud claim).
        'client_ids' => array_values(array_filter([
            env('GOOGLE_WEB_CLIENT_ID'),
            env('GOOGLE_IOS_CLIENT_ID'),
            env('GOOGLE_ANDROID_CLIENT_ID'),
        ])),
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => ['accounts.google.com', 'https://accounts.google.com'],
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', 'uec'),
        'timeout' => 30,
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
